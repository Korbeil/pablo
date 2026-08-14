<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;
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

    public static function projectsDir(?string $override = null): string
    {
        if (null !== $override && '' !== $override) {
            return $override;
        }
        $env = getenv('PABLO_PROJECTS_DIR');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        // Projects live per-user outside the repo, under ~/.pablo/projects.
        // Resolved at runtime so the PABLO_PROJECTS_DIR override and HOME are
        // read fresh (same reason GlobalConfig resolves its own path).
        return (getenv('HOME') ?: '~').'/.pablo/projects';
    }

    /** @return array<string, ProjectConfig> */
    public static function loadProjects(?string $directory = null): array
    {
        $directory ??= self::projectsDir();
        if (!is_dir($directory)) {
            throw new PabloError("projects directory not found: {$directory}");
        }
        $defaults = GlobalConfig::defaults();

        $projects = [];
        foreach (glob(rtrim($directory, '/').'/*.yaml') ?: [] as $path) {
            $cfg = self::parseProject($path, $defaults);
            if (isset($projects[$cfg->name])) {
                throw new PabloError(basename($path).": duplicate project name '{$cfg->name}'");
            }
            $projects[$cfg->name] = $cfg;
        }

        return $projects;
    }

    /** @return array<string, mixed> */
    public static function loadYaml(string $path): array
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
    private static function require(array $data, string $dotted, string $path): mixed
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
     * @param array<string, mixed> $defaults
     */
    private static function merged(array $data, array $defaults, string $section, string $key): mixed
    {
        foreach ([$data, $defaults] as $source) {
            $value = $source[$section] ?? null;
            if (\is_array($value) && \array_key_exists($key, $value)) {
                return $value[$key];
            }
        }
        throw new PabloError("no value for {$section}.{$key} — set it in the project config or ~/.pablo/config.yaml");
    }

    private static function expandHome(string $path): string
    {
        if (str_starts_with($path, '~/')) {
            return getenv('HOME').substr($path, 1);
        }

        return $path;
    }

    /**
     * @param array<string, mixed> $defaults
     */
    private static function parseProject(string $path, array $defaults): ProjectConfig
    {
        $data = self::loadYaml($path);
        $name = basename($path);
        $base = $path;

        $projectName = (string) self::require($data, 'name', $base);
        $type = (string) self::require($data, 'type', $base);
        if (!\in_array($type, self::PROJECT_TYPES, true)) {
            throw new PabloError(\sprintf('%s: unknown type %s (expected one of %s)', $name, var_export($type, true), '["open-source", "personal", "work"]'));
        }

        $repoPath = self::expandHome((string) self::require($data, 'repo.path', $base));
        $primaryBranch = (string) self::require($data, 'repo.primary_branch', $base);

        $provider = (string) self::require($data, 'issue_tracker.provider', $base);
        if (!\in_array($provider, self::PROVIDERS, true)) {
            throw new PabloError(\sprintf('%s: unknown provider %s (expected one of %s)', $name, var_export($provider, true), '["github", "jira", "linear"]'));
        }
        $identity = (string) self::require($data, 'issue_tracker.identity', $base);
        $projectKey = $data['issue_tracker']['project_key'] ?? null;
        if (null === $projectKey) {
            throw new PabloError(\sprintf("%s: missing required key 'issue_tracker.project_key'", $name));
        }

        $rawRoot = $data['worktrees_root'] ?? null;
        if (null !== $rawRoot && '' !== $rawRoot) {
            $worktreesRoot = self::expandHome((string) $rawRoot);
        } else {
            $worktreesRoot = self::expandHome('~/.pablo/worktrees').'/'.basename($repoPath);
        }

        $syncInterval = self::merged($data, $defaults, 'sync', 'interval_minutes');
        $pollInterval = self::merged($data, $defaults, 'state_polling', 'interval_minutes');
        $strategy = self::merged($data, $defaults, 'sync', 'strategy');
        if (!\in_array($strategy, ['rebase', 'merge'], true)) {
            throw new PabloError(\sprintf('%s: sync.strategy must be \'rebase\' or \'merge\', got %s', $name, var_export($strategy, true)));
        }

        $startup = $data['startup_script'] ?? null;

        return new ProjectConfig(
            name: $projectName,
            type: $type,
            repoPath: $repoPath,
            primaryBranch: $primaryBranch,
            worktreesRoot: $worktreesRoot,
            provider: $provider,
            identity: $identity,
            projectKey: (string) $projectKey,
            issueRepo: isset($data['issue_tracker']['repo']) && '' !== $data['issue_tracker']['repo'] ? (string) $data['issue_tracker']['repo'] : null,
            site: $data['issue_tracker']['site'] ?? null,
            confluenceSpace: $data['confluence']['space'] ?? null,
            syncStrategy: (string) $strategy,
            syncAutoApply: (bool) self::merged($data, $defaults, 'sync', 'auto_apply'),
            syncInterval: (int) $syncInterval,
            pollInterval: (int) $pollInterval,
            failureSignal: $data['testing']['failure_signal'] ?? null,
            botWhitelist: array_values((array) self::merged($data, $defaults, 'review', 'bot_whitelist')),
            ciIgnoreChecks: array_values((array) self::merged($data, $defaults, 'ci', 'ignore_checks')),
            startupScript: $startup ? self::expandHome((string) $startup) : null,
        );
    }
}
