<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Domain\State;
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
        $defaults ??= $this->global->defaults();
        $this->checkGlobalDefaults($defaults);

        return $this->parseProject($path, $defaults);
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
        $this->checkGlobalDefaults($defaults);

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

    /**
     * Checks on ~/.pablo/config.yaml alone: they would fail every project.
     *
     * @param array<string, mixed> $defaults
     */
    private function checkGlobalDefaults(array $defaults): void
    {
        $byType = \is_array($defaults['default_model_by_type'] ?? null) ? $defaults['default_model_by_type'] : [];
        foreach (array_keys($byType) as $typeKey) {
            if (!\in_array((string) $typeKey, self::PROJECT_TYPES, true)) {
                throw new PabloError(\sprintf('config.yaml: default_model_by_type has unknown type %s (expected one of ["open-source", "personal", "work"])', var_export((string) $typeKey, true)));
            }
        }
        // The global hooks block on its own: an error there is every project's.
        $this->parseHooks([], $defaults, 'config.yaml');
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
     * @param array<string, mixed> $data
     */
    private function require(array $data, string $dotted, string $path): mixed
    {
        $node = $data;
        foreach (explode('.', $dotted) as $part) {
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                throw new PabloError(basename($path).": missing required key '{$dotted}'");
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function optional(array $data, string $dotted): mixed
    {
        $node = $data;
        foreach (explode('.', $dotted) as $part) {
            if (!\is_array($node) || !\array_key_exists($part, $node)) {
                return null;
            }
            $node = $node[$part];
        }

        return $node;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function merged(array $data, array $defaults, string $section, string $key): mixed
    {
        foreach ([$data, $defaults] as $source) {
            $value = $source[$section] ?? null;
            if (\is_array($value) && \array_key_exists($key, $value)) {
                return $value[$key];
            }
        }
        throw new PabloError("no value for {$section}.{$key} — set it in the project config or ~/.pablo/config.yaml");
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private function parseProject(string $path, array $defaults): ProjectConfig
    {
        $data = $this->loadYaml($path);
        $name = basename($path);
        $base = $path;

        $projectName = (string) $this->require($data, 'name', $base);
        $type = (string) $this->require($data, 'type', $base);
        if (!\in_array($type, self::PROJECT_TYPES, true)) {
            throw new PabloError(\sprintf('%s: unknown type %s (expected one of %s)', $name, var_export($type, true), '["open-source", "personal", "work"]'));
        }

        $repoPath = PabloPaths::expandTilde((string) $this->require($data, 'repo.path', $base));
        $primaryBranch = (string) $this->require($data, 'repo.primary_branch', $base);
        $primaryRemote = (string) ($this->optional($data, 'repo.primary_remote') ?? 'origin');

        $provider = (string) $this->require($data, 'issue_tracker.provider', $base);
        if (!\in_array($provider, self::PROVIDERS, true)) {
            throw new PabloError(\sprintf('%s: unknown provider %s (expected one of %s)', $name, var_export($provider, true), '["github", "jira", "linear"]'));
        }
        $identity = (string) $this->require($data, 'issue_tracker.identity', $base);
        $projectKey = $data['issue_tracker']['project_key'] ?? null;
        if (null === $projectKey) {
            throw new PabloError(\sprintf("%s: missing required key 'issue_tracker.project_key'", $name));
        }

        $rawRoot = $data['worktrees_root'] ?? null;
        if (null !== $rawRoot && '' !== $rawRoot) {
            $worktreesRoot = PabloPaths::expandTilde((string) $rawRoot);
        } else {
            $worktreesRoot = PabloPaths::pabloPath('worktrees').'/'.basename($repoPath);
        }

        $syncInterval = $this->merged($data, $defaults, 'sync', 'interval_minutes');
        $pollInterval = $this->merged($data, $defaults, 'state_polling', 'interval_minutes');
        $strategy = $this->merged($data, $defaults, 'sync', 'strategy');
        if (!\in_array($strategy, ['rebase', 'merge'], true)) {
            throw new PabloError(\sprintf('%s: sync.strategy must be \'rebase\' or \'merge\', got %s', $name, var_export($strategy, true)));
        }

        $startup = $data['startup_script'] ?? null;

        // Resolution: project default_model > default_model_by_type[<project
        // type>] (global only) > global default_model. The resolved value is
        // what internal:launch-agent passes to the agent backends as --model.
        $byType = \is_array($defaults['default_model_by_type'] ?? null) ? $defaults['default_model_by_type'] : [];
        $typeModel = isset($byType[$type]) && '' !== (string) $byType[$type] ? (string) $byType[$type] : null;
        $defaultModel = $data['default_model'] ?? $typeModel ?? ($defaults['default_model'] ?? null);
        if (null === $defaultModel || '' === (string) $defaultModel) {
            throw new PabloError('no value for default_model — set it in the project config, default_model_by_type, or ~/.pablo/config.yaml');
        }
        $defaultModel = (string) $defaultModel;
        // The model id format depends on the runtime that receives it:
        // each runtime says what it accepts.
        $modelError = (new AgentRuntimeRegistry($this->global))->resolve(AgentRuntimeRegistry::nameFrom($defaults))->modelError($defaultModel);
        if (null !== $modelError) {
            throw new PabloError("{$name}: default_model {$modelError}");
        }
        $prDescriptionLocale = $data['pr_description_locale'] ?? $defaults['pr_description_locale'] ?? null;
        if (null === $prDescriptionLocale) {
            throw new PabloError('no value for pr_description_locale — set it in the project config or ~/.pablo/config.yaml');
        }
        $branchPrefix = $data['branch_prefix'] ?? $defaults['branch_prefix'] ?? null;
        if (null !== $branchPrefix && '' === (string) $branchPrefix) {
            $branchPrefix = null;
        } elseif (null !== $branchPrefix) {
            $branchPrefix = (string) $branchPrefix;
        }
        [$hookCommand, $hookStates, $hookAgentFinished] = $this->parseHooks($data, $defaults, $name);

        return new ProjectConfig(
            name: $projectName,
            type: $type,
            repoPath: $repoPath,
            primaryBranch: $primaryBranch,
            primaryRemote: $primaryRemote,
            worktreesRoot: $worktreesRoot,
            provider: $provider,
            identity: $identity,
            projectKey: (string) $projectKey,
            issueRepo: isset($data['issue_tracker']['repo']) && '' !== $data['issue_tracker']['repo'] ? (string) $data['issue_tracker']['repo'] : null,
            site: $data['issue_tracker']['site'] ?? null,
            confluenceSpace: $data['confluence']['space'] ?? null,
            syncStrategy: (string) $strategy,
            syncAutoApply: (bool) $this->merged($data, $defaults, 'sync', 'auto_apply'),
            syncInterval: (int) $syncInterval,
            pollInterval: (int) $pollInterval,
            failureSignal: $data['testing']['failure_signal'] ?? null,
            testingEnabled: (bool) ($data['testing']['enabled'] ?? $defaults['testing']['enabled'] ?? true),
            mainUpdateEnabled: (bool) ($data['main_update']['enabled'] ?? $defaults['main_update']['enabled'] ?? true),
            mainUpdateInterval: (int) ($data['main_update']['interval_minutes'] ?? $defaults['main_update']['interval_minutes'] ?? 60),
            botWhitelist: array_values((array) $this->merged($data, $defaults, 'review', 'bot_whitelist')),
            ciIgnoreChecks: array_values((array) $this->merged($data, $defaults, 'ci', 'ignore_checks')),
            startupScript: $startup ? PabloPaths::expandTilde((string) $startup) : null,
            defaultModel: $defaultModel,
            prDescriptionLocale: $prDescriptionLocale,
            branchPrefix: $branchPrefix,
            hookCommand: $hookCommand,
            hookStates: $hookStates,
            hookAgentFinished: $hookAgentFinished,
        );
    }

    /**
     * hooks.<key>: the project's value when it sets the key (even to null,
     * which turns the hook off for that project), else the global one.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     */
    private function hookValue(array $data, array $defaults, string $key): mixed
    {
        foreach ([$data, $defaults] as $source) {
            $hooks = $source['hooks'] ?? null;
            if (\is_array($hooks) && \array_key_exists($key, $hooks)) {
                return $hooks[$key];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $defaults
     *
     * @return array{0: ?string, 1: list<State>|null, 2: bool}
     */
    private function parseHooks(array $data, array $defaults, string $name): array
    {
        if (\array_key_exists('hooks', $data) && null !== $data['hooks'] && !\is_array($data['hooks'])) {
            throw new PabloError("{$name}: hooks must be a mapping (command, states, agent_finished)");
        }
        $command = $this->hookValue($data, $defaults, 'command');
        if (null !== $command && !\is_string($command)) {
            throw new PabloError("{$name}: hooks.command must be a shell command string");
        }
        $command = null !== $command && '' !== trim($command) ? $command : null;

        $rawStates = $this->hookValue($data, $defaults, 'states');
        $states = null;
        if (null !== $rawStates) {
            if (!\is_array($rawStates)) {
                throw new PabloError("{$name}: hooks.states must be a list of states");
            }
            $states = [];
            foreach ($rawStates as $raw) {
                $state = \is_string($raw) ? State::tryFrom($raw) : null;
                if (null === $state) {
                    throw new PabloError(\sprintf('%s: hooks.states has unknown state %s (expected one of %s)', $name, var_export($raw, true), implode(', ', array_map(static fn (State $s): string => $s->value, State::cases()))));
                }
                $states[] = $state;
            }
        }

        $agentFinished = $this->hookValue($data, $defaults, 'agent_finished') ?? true;
        if (!\is_bool($agentFinished)) {
            throw new PabloError("{$name}: hooks.agent_finished must be true or false");
        }

        return [$command, $states, $agentFinished];
    }
}
