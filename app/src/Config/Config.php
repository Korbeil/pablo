<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Project configuration loading.
 *
 * Reads projects/*.yaml and merges per key: a project value wins, a missing
 * key falls back to the PABLO-wide defaults in ~/.pablo/config.yaml
 * (see GlobalConfig).
 */
final class Config
{
    public const PROJECT_TYPES = ['work', 'open-source', 'personal'];

    public const PROVIDERS = ['github', 'jira', 'linear'];

    public function __construct(private readonly GlobalConfig $global)
    {
    }

    /** @var array<string, ProjectConfig>|null */
    private ?array $projectsCache = null;

    public function projectsDir(?string $override = null): string
    {
        if (null !== $override && '' !== $override) {
            return $override;
        }

        // Projects live per-user outside the repo, under ~/.pablo/projects.
        // Resolved at runtime so the PABLO_PROJECTS_DIR override and HOME are
        // read fresh (same reason GlobalConfig resolves its own path).
        return PabloPaths::pabloPath('projects', 'PABLO_PROJECTS_DIR');
    }

    /** @var array<string, string>|null file path => error, for the cached default load */
    private ?array $projectErrorsCache = null;

    /** @var array<string, string>|null project name => error, for the cached default load */
    private ?array $brokenNamesCache = null;

    /**
     * Loaded once per instance: commands may ask for the project map several
     * times in a single run, and each load re-reads every YAML file.
     *
     * A broken project file (invalid YAML, a missing or invalid key, a
     * duplicate name) never hides the others: it is left out of the returned
     * map and its error is kept for projectErrors() — one project's failure
     * never blocks the others. Errors that concern every project (the global
     * ~/.pablo/config.yaml, a missing projects directory) still throw.
     *
     * @return array<string, ProjectConfig>
     */
    public function loadProjects(?string $directory = null): array
    {
        return $this->load($directory)[0];
    }

    /**
     * The project files that failed to load, file path => error message
     * (already prefixed with the file name). Empty when every file is valid.
     *
     * @return array<string, string>
     */
    public function projectErrors(?string $directory = null): array
    {
        return $this->load($directory)[1];
    }

    /**
     * The error to raise when a command targets $project and it is not in
     * loadProjects(): the broken file's own error when the project exists but
     * its config is invalid, else "unknown project".
     */
    public function unknownProject(string $project): PabloError
    {
        [$projects, , $broken] = $this->load(null);
        if (isset($broken[$project])) {
            return new PabloError("project '{$project}' has an invalid config: {$broken[$project]}");
        }

        return new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
    }

    /**
     * The error to raise when a task's project is not in loadProjects(): its
     * config is either invalid (say why) or gone.
     */
    public function missingTaskProject(string $project, string $branch): PabloError
    {
        $broken = $this->load(null)[2];
        if (isset($broken[$project])) {
            return new PabloError("task {$branch} belongs to project '{$project}', whose config is invalid: {$broken[$project]}");
        }

        return new PabloError("task {$branch} belongs to project '{$project}', which has no config under projects/ anymore");
    }

    /**
     * One project file, validated on its own (throws its error).
     *
     * @param array<string, mixed>|null $defaults
     */
    public function loadProjectFile(string $path, ?array $defaults = null): ProjectConfig
    {
        return $this->parseProject($path, $defaults ?? $this->global->defaults());
    }

    /**
     * The valid projects, file path => error, and broken project name => error.
     *
     * @return array{0: array<string, ProjectConfig>, 1: array<string, string>, 2: array<string, string>}
     */
    private function load(?string $directory): array
    {
        $useCache = null === $directory;
        if ($useCache && null !== $this->projectsCache && null !== $this->projectErrorsCache && null !== $this->brokenNamesCache) {
            return [$this->projectsCache, $this->projectErrorsCache, $this->brokenNamesCache];
        }
        $directory ??= $this->projectsDir();
        if (!is_dir($directory)) {
            throw new PabloError("projects directory not found: {$directory}");
        }
        // Global config errors affect every project: fatal, never per file.
        $defaults = $this->global->defaults();

        $projects = [];
        $paths = [];
        $errors = [];
        $broken = [];
        foreach (glob(rtrim($directory, '/').'/*.yaml') ?: [] as $path) {
            try {
                $cfg = $this->parseProject($path, $defaults);
            } catch (PabloError $e) {
                $errors[$path] = $e->getMessage();
                foreach ($this->namesFor($path) as $name) {
                    $broken[$name] = $e->getMessage();
                }
                continue;
            }
            if (isset($paths[$cfg->name])) {
                // Ambiguous: neither file is trusted, both are reported.
                $message = "duplicate project name '{$cfg->name}' (also in ".basename($paths[$cfg->name]).')';
                $errors[$path] = basename($path).": {$message}";
                if (isset($projects[$cfg->name])) {
                    $errors[$paths[$cfg->name]] = basename($paths[$cfg->name]).": duplicate project name '{$cfg->name}' (also in ".basename($path).')';
                    unset($projects[$cfg->name]);
                }
                $broken[$cfg->name] = $errors[$path];
                continue;
            }
            $paths[$cfg->name] = $path;
            $projects[$cfg->name] = $cfg;
        }
        if ($useCache) {
            $this->projectsCache = $projects;
            $this->projectErrorsCache = $errors;
            $this->brokenNamesCache = $broken;
        }

        return [$projects, $errors, $broken];
    }

    /**
     * The project names a broken file may stand for, so a command targeting
     * it can report the real error: its "name" key when the YAML still parses,
     * and its file name.
     *
     * @return list<string>
     */
    private function namesFor(string $path): array
    {
        $names = [basename($path, '.yaml')];
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException) {
            return $names;
        }
        if (\is_array($data) && \is_string($data['name'] ?? null) && '' !== $data['name']) {
            $names[] = $data['name'];
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, mixed> */
    public function loadYaml(string $path): array
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            throw new PabloError(basename($path).': invalid YAML: '.$e->getMessage());
        }
        if (!\is_array($data)) {
            throw new PabloError(basename($path).': expected a mapping at top level');
        }

        return $data;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function parseProject(string $path, array $defaults): ProjectConfig
    {
        $data = $this->loadYaml($path);
        $file = basename($path);
        // Types and ranges of every key it sets (see ConfigSchema); unknown
        // keys are ignored here, reported by validate and the doctor.
        $error = ConfigSchema::loadError($data, ConfigSchema::PROJECT, $path, $file);
        if (null !== $error) {
            throw new PabloError($error->text());
        }

        $projectName = (string) ConfigValues::required($data, 'name', $file);
        // Its value is one of PROJECT_TYPES (checked by the schema), when set.
        $type = (string) (ConfigValues::required($data, 'type', $file) ?? throw new PabloError("{$file}: missing required key 'type'"));

        return new ProjectConfig(
            name: $projectName,
            type: $type,
            repo: RepoConfig::fromYaml($data, $file),
            tracker: TrackerConfig::fromYaml($data, $file),
            sync: SyncConfig::fromYaml($data, $defaults),
            statePolling: StatePollingConfig::fromYaml($data, $defaults),
            defaultModel: $this->defaultModel($data, $defaults, $type, $file),
            prDescriptionLocale: $this->prDescriptionLocale($data, $defaults),
            review: ReviewConfig::fromYaml($data, $defaults),
            ci: CiConfig::fromYaml($data, $defaults),
            testing: TestingConfig::fromYaml($data, $defaults),
            mainUpdate: MainUpdateConfig::fromYaml($data, $defaults),
            hooks: HooksConfig::fromYaml($data, $defaults),
            branchPrefix: $this->branchPrefix($data, $defaults),
            confluenceSpace: $data['confluence']['space'] ?? null,
        );
    }

    /**
     * Resolution: project default_model > default_model_by_type[<project
     * type>] (global only) > global default_model. The resolved value is
     * what internal:launch-agent passes to the agent backends as --model.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function defaultModel(array $data, array $defaults, string $type, string $file): string
    {
        $byType = \is_array($defaults['default_model_by_type'] ?? null) ? $defaults['default_model_by_type'] : [];
        $typeModel = isset($byType[$type]) && '' !== (string) $byType[$type] ? (string) $byType[$type] : null;
        $defaultModel = $data['default_model'] ?? $typeModel ?? ($defaults['default_model'] ?? null);
        if (null === $defaultModel || '' === (string) $defaultModel) {
            throw new PabloError('no value for default_model — set it in the project config, default_model_by_type, or ~/.pablo/config.yaml');
        }
        $defaultModel = (string) $defaultModel;
        // The model id format depends on the runtime that receives it:
        // each runtime says what it accepts. Built here, not injected: the
        // registry depends on AgentTemplates, which depends on this loader.
        $modelError = (new AgentRuntimeRegistry($this->global, new AgentTemplates($this)))->resolve(AgentRuntimeRegistry::nameFrom($defaults))->modelError($defaultModel);
        if (null !== $modelError) {
            throw new PabloError("{$file}: default_model {$modelError}");
        }

        return $defaultModel;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function prDescriptionLocale(array $data, array $defaults): string
    {
        $locale = $data['pr_description_locale'] ?? $defaults['pr_description_locale'] ?? null;
        if (null === $locale) {
            throw new PabloError('no value for pr_description_locale — set it in the project config or ~/.pablo/config.yaml');
        }

        return (string) $locale;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function branchPrefix(array $data, array $defaults): ?string
    {
        $prefix = $data['branch_prefix'] ?? $defaults['branch_prefix'] ?? null;

        return null === $prefix || '' === (string) $prefix ? null : (string) $prefix;
    }
}
