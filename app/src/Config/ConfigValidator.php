<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Every check on ~/.pablo/config.yaml and projects/*.yaml at once —
 * `system:config:validate` and the doctor's config section. Read-only.
 *
 * Beyond what loading checks (ConfigSchema, required keys, the model id,
 * duplicate names), it reports unknown keys (warnings) and the paths a
 * project points at (errors): repo.path is a git checkout, an absolute
 * startup_script exists, worktrees_root's parent exists. Loading never
 * checks paths — a repository on a disk that isn't mounted yet must not
 * break every command — so they are only reported here.
 */
final class ConfigValidator
{
    public const GLOBAL_LABEL = 'config.yaml';

    public function __construct(
        private readonly GlobalConfig $global,
        private readonly Config $config,
    ) {
    }

    public function validate(): ConfigReport
    {
        $files = [];
        $issues = [];

        $globalPath = $this->global->configPath();
        if (is_file($globalPath)) {
            $files[] = $globalPath;
            $data = $this->parse($globalPath, self::GLOBAL_LABEL, $issues);
            if (null !== $data) {
                $issues = [...$issues, ...ConfigSchema::check($data, ConfigSchema::GLOBAL, $globalPath, self::GLOBAL_LABEL)];
            }
        } else {
            $issues[] = ConfigIssue::warning($globalPath, self::GLOBAL_LABEL, '', 'not found: projects get no global defaults, each must set every key itself');
        }
        $globalError = null;
        try {
            $this->global->defaults();
        } catch (PabloError $e) {
            $globalError = $e->getMessage();
        }

        $directory = $this->config->projectsDir();
        if (!is_dir($directory)) {
            $issues[] = ConfigIssue::error($directory, 'projects', '', "directory not found: {$directory}");

            return new ConfigReport($files, $issues, $globalPath, $globalError);
        }
        $paths = glob(rtrim($directory, '/').'/*.yaml') ?: [];
        sort($paths);
        /** @var array<string, list<string>> $byName project name => files */
        $byName = [];
        foreach ($paths as $path) {
            $files[] = $path;
            $label = basename($path);
            $data = $this->parse($path, $label, $issues);
            if (null === $data) {
                continue;
            }
            $own = ConfigSchema::check($data, ConfigSchema::PROJECT, $path, $label);
            $issues = [...$issues, ...$own];
            // A schema error is the loader's error too, and a broken
            // config.yaml fails every project: nothing more to learn.
            if (null !== $globalError || [] !== array_filter($own, static fn (ConfigIssue $i): bool => $i->isError())) {
                continue;
            }
            try {
                $project = $this->config->loadProjectFile($path);
            } catch (PabloError $e) {
                $message = $e->getMessage();
                if (str_starts_with($message, $label.': ')) {
                    $message = substr($message, \strlen($label) + 2);
                }
                $issues[] = ConfigIssue::error($path, $label, '', $message);
                continue;
            }
            $byName[$project->name][] = $path;
            $issues = [...$issues, ...$this->pathIssues($project, $data, $path, $label)];
        }
        foreach ($byName as $name => $named) {
            if (\count($named) < 2) {
                continue;
            }
            foreach ($named as $path) {
                $others = array_map(basename(...), array_values(array_diff($named, [$path])));
                $issues[] = ConfigIssue::error($path, basename($path), 'name', \sprintf("duplicate project name '%s' (also in %s): both files are skipped", $name, implode(', ', $others)));
            }
        }

        return new ConfigReport($files, $issues, $globalPath, $globalError);
    }

    /**
     * The file's mapping, or null after recording why it has none.
     *
     * @param list<ConfigIssue> $issues
     *
     * @return array<mixed>|null
     */
    private function parse(string $path, string $label, array &$issues): ?array
    {
        try {
            $data = Yaml::parseFile($path);
        } catch (ParseException $e) {
            $issues[] = ConfigIssue::error($path, $label, '', 'invalid YAML: '.$e->getMessage());

            return null;
        }
        if (!\is_array($data)) {
            $issues[] = ConfigIssue::error($path, $label, '', 'expected a mapping at top level');

            return null;
        }

        return $data;
    }

    /**
     * @param array<mixed> $data the file as written (to tell a set worktrees_root from the default)
     *
     * @return list<ConfigIssue>
     */
    private function pathIssues(ProjectConfig $project, array $data, string $path, string $label): array
    {
        $issues = [];
        if (!is_dir($project->repo->path)) {
            $issues[] = ConfigIssue::error($path, $label, 'repo.path', "is not a directory: {$project->repo->path}");
        } elseif (!file_exists($project->repo->path.'/.git')) {
            $issues[] = ConfigIssue::error($path, $label, 'repo.path', "is not a git repository (no .git): {$project->repo->path}");
        }
        // A relative script runs from the task's worktree: nothing to check
        // before a task exists.
        $script = $project->repo->startupScript;
        if (null !== $script && str_starts_with($script, '/') && !is_file($script)) {
            $issues[] = ConfigIssue::error($path, $label, 'startup_script', "file not found: {$script}");
        }
        $root = $data['worktrees_root'] ?? null;
        if (\is_string($root) && '' !== $root) {
            // The root itself is created with the first worktree.
            $parent = \dirname(PabloPaths::expandTilde($root));
            if (!is_dir($parent)) {
                $issues[] = ConfigIssue::error($path, $label, 'worktrees_root', "parent directory not found: {$parent}");
            }
        }

        return $issues;
    }
}
