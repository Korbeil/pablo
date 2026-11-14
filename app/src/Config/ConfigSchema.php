<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Domain\Agent;
use Pablo\Domain\State;
use Pablo\Web\TrustedHosts;

/**
 * Every key PABLO reads from ~/.pablo/config.yaml (GLOBAL) and from a
 * project file (PROJECT), with the type and range of its value — the one
 * list both loaders (GlobalConfig, Config) and `system:config:validate` check
 * against.
 *
 * An unknown key is a warning, never an error by itself (a file written for a
 * newer PABLO keeps loading): it is ignored, and reported with the closest
 * known key ("did you mean …?") or, for a key of the other file, where it
 * belongs. An invalid value is an error. A null value means "unset", like a
 * missing key. Required keys are not listed as such: the project loader
 * reports a missing one with the key that is missing.
 */
final class ConfigSchema
{
    public const GLOBAL = 'global';
    public const PROJECT = 'project';

    /** @var array<string, array<string, SchemaKey>> scope => root keys */
    private static array $roots = [];

    /**
     * The problems in $data, a file's parsed YAML. $forLoad leaves out the
     * deferred keys (see SchemaKey::$deferred): what a loader fails on.
     *
     * @param array<mixed> $data
     *
     * @return list<ConfigIssue>
     */
    public static function check(array $data, string $scope, string $file, string $label, bool $forLoad = false): array
    {
        $issues = [];
        self::walk($data, self::root($scope), '', $scope, $file, $label, $forLoad, $issues);

        return $issues;
    }

    /**
     * The first error a loader fails on, null when the file loads.
     *
     * @param array<mixed> $data
     */
    public static function loadError(array $data, string $scope, string $file, string $label): ?ConfigIssue
    {
        foreach (self::check($data, $scope, $file, $label, true) as $issue) {
            if ($issue->isError()) {
                return $issue;
            }
        }

        return null;
    }

    /**
     * The problem with $value as the value of the dotted $keyPath of $scope
     * ("must be …", what follows the key in a message), null when valid or
     * unset: for the code reading a deferred key, so its error is worded as
     * validate and the doctor word it.
     *
     * @throws \LogicException when $keyPath is not a key of $scope
     */
    public static function valueError(string $scope, string $keyPath, mixed $value): ?string
    {
        $node = null;
        $children = self::root($scope);
        foreach (explode('.', $keyPath) as $part) {
            $node = $children[$part] ?? throw new \LogicException("unknown {$scope} config key {$keyPath}");
            $children = $node->children ?? [];
        }
        if (null === $value) {
            return null;
        }
        if (null !== $node->children) {
            return \is_array($value) && ([] === $value || !array_is_list($value)) ? null : "must be {$node->mapping}, got ".self::show($value);
        }

        return null !== $node->check ? ($node->check)($value) : null;
    }

    /**
     * Whether the dotted $path is a key of $scope.
     */
    public static function knows(string $scope, string $path): bool
    {
        $children = self::root($scope);
        foreach (explode('.', $path) as $part) {
            if (null === $children || !isset($children[$part])) {
                return false;
            }
            $children = $children[$part]->children;
        }

        return true;
    }

    /** @return array<string, SchemaKey> */
    private static function root(string $scope): array
    {
        return self::$roots[$scope] ??= self::PROJECT === $scope ? self::projectKeys() : self::globalKeys();
    }

    /**
     * @param array<mixed>             $data
     * @param array<string, SchemaKey> $known
     * @param list<ConfigIssue>        $issues
     */
    private static function walk(array $data, array $known, string $prefix, string $scope, string $file, string $label, bool $forLoad, array &$issues, ?string $closed = null): void
    {
        foreach ($data as $key => $value) {
            $key = (string) $key;
            $path = $prefix.$key;
            $node = $known[$key] ?? null;
            if (null === $node) {
                if (null !== $closed) {
                    $issues[] = ConfigIssue::error($file, $label, rtrim($prefix, '.'), \sprintf('has unknown %s %s (expected one of: %s)', $closed, var_export($key, true), implode(', ', array_keys($known))));
                } elseif (!$forLoad) {
                    $issues[] = ConfigIssue::warning($file, $label, $path, self::unknownKey($scope, $prefix, $key, $known));
                }
                continue;
            }
            if (($forLoad && $node->deferred) || null === $value) {
                continue;
            }
            if (null !== $node->children) {
                if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
                    $issues[] = ConfigIssue::error($file, $label, $path, "must be {$node->mapping}, got ".self::show($value));
                    continue;
                }
                self::walk($value, $node->children, $path.'.', $scope, $file, $label, $forLoad, $issues, $node->closed);
                continue;
            }
            $problem = null !== $node->check ? ($node->check)($value) : null;
            if (null !== $problem) {
                $issues[] = ConfigIssue::error($file, $label, $path, $problem);
            }
        }
    }

    /**
     * The warning for an unknown key: where it belongs when it is a key of
     * the other file, else the closest known key at the same level.
     *
     * @param array<string, SchemaKey> $known
     */
    private static function unknownKey(string $scope, string $prefix, string $key, array $known): string
    {
        $path = $prefix.$key;
        $other = self::PROJECT === $scope ? self::GLOBAL : self::PROJECT;
        if (self::knows($other, $path)) {
            return self::PROJECT === $scope
                ? 'is a global-only key, ignored in a project file: set it in ~/.pablo/config.yaml'
                : 'is a project-only key, ignored in config.yaml: set it in a project file';
        }
        $suggestion = self::closest($key, array_keys($known));

        return 'is not a known key, ignored'.(null !== $suggestion ? "; did you mean `{$prefix}{$suggestion}`?" : '');
    }

    /**
     * The known key closest to $key, when close enough to be a typo.
     *
     * @param list<string> $candidates
     */
    public static function closest(string $key, array $candidates): ?string
    {
        $best = null;
        $bestDistance = \PHP_INT_MAX;
        $needle = strtolower($key);
        foreach ($candidates as $candidate) {
            // A key cut short (interval → interval_minutes).
            if (\strlen($needle) >= 4 && str_starts_with($candidate, $needle)) {
                return $candidate;
            }
        }
        foreach ($candidates as $candidate) {
            $distance = levenshtein($needle, $candidate);
            if ($distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }
        if (null === $best) {
            return null;
        }
        // A typo: a few edits, relative to the key's length (ci → cl is not
        // a typo of anything, ignore_check → ignore_checks is).
        $tolerance = max(1, min(3, intdiv(\strlen($best), 4)));

        return $bestDistance <= $tolerance ? $best : null;
    }

    /** @return array<string, SchemaKey> the keys a project inherits: in both files */
    private static function inherited(bool $project): array
    {
        $testing = ['enabled' => self::leaf(self::flag(...))];
        if ($project) {
            $testing['failure_signal'] = self::leaf(self::text(...));
        }

        return [
            'sync' => SchemaKey::map([
                'strategy' => self::leaf(self::enum(['rebase', 'merge'])),
                'auto_apply' => self::leaf(self::flag(...)),
                'interval_minutes' => self::leaf(self::positiveInt('minutes')),
            ]),
            'state_polling' => SchemaKey::map([
                'interval_minutes' => self::leaf(self::positiveInt('minutes')),
            ]),
            'main_update' => SchemaKey::map([
                'enabled' => self::leaf(self::flag(...)),
                'interval_minutes' => self::leaf(self::positiveInt('minutes')),
            ]),
            'review' => SchemaKey::map([
                'bot_whitelist' => self::leaf(self::stringList('bot logins')),
            ]),
            'ci' => SchemaKey::map([
                'ignore_checks' => self::leaf(self::stringList('check names')),
            ]),
            'testing' => SchemaKey::map($testing),
            'default_model' => self::leaf(self::text(...)),
            'pr_description_locale' => self::leaf(self::text(...)),
            'branch_prefix' => self::leaf(self::text(...)),
            'hooks' => SchemaKey::map([
                'command' => self::leaf(static fn (mixed $v): ?string => \is_string($v) ? null : 'must be a shell command string, got '.self::show($v)),
                'states' => self::leaf(self::states(...)),
                'agent_finished' => self::leaf(self::flag(...)),
            ], mapping: 'a mapping (command, states, agent_finished)'),
        ];
    }

    /** @return array<string, SchemaKey> */
    private static function globalKeys(): array
    {
        $byType = [];
        foreach (Config::PROJECT_TYPES as $type) {
            $byType[$type] = self::leaf(self::typeModel(...));
        }

        return self::inherited(false) + [
            'models' => SchemaKey::map($byType, closed: 'type', mapping: 'a mapping of project type to model (or to a mapping of agent to model)'),
            // Its former name: an error rather than an ignored unknown key,
            // which would silently put its projects on the global model.
            // system:install renames it (ConfigSeeder::RENAMED_KEYS).
            'default_model_by_type' => self::leaf(static fn (mixed $v): string => 'was renamed to `models`: rename the key, or run `pablo system:install`'),
            // Names checked by the registries (one place lists them), which
            // the doctor and system:config:validate report.
            'agent_backend' => self::leaf(self::text(...)),
            'agent_runtime' => self::leaf(self::text(...)),
            // Deferred: each is refused by the command reading it (pablo web,
            // system:install, system:prune), never by every command.
            'claude_code' => SchemaKey::map([
                'remote_control' => self::leaf(self::flag(...), deferred: true),
            ], deferred: true),
            'dashboard_port' => self::leaf(static fn (mixed $v): ?string => GlobalConfig::isPort($v) ? null : 'must be a port number (1-65535), got '.self::show($v), deferred: true),
            'dashboard_trusted_hosts' => self::leaf(self::hosts(...), deferred: true),
            'scheduler' => SchemaKey::map([
                'path' => self::leaf(self::directories(...), deferred: true),
            ], deferred: true),
            'prune' => SchemaKey::map([
                'keep_days' => self::leaf(self::positiveInt('days'), deferred: true),
            ], deferred: true),
            'shell_completion' => self::leaf(self::flag(...), deferred: true),
            // Read by the dispatcher alone, which falls back to 1 on a bad value.
            'dispatch' => SchemaKey::map([
                'poll_concurrency' => self::leaf(self::intRange(1, GlobalConfig::MAX_POLL_CONCURRENCY, 'projects'), deferred: true),
            ], deferred: true),
        ];
    }

    /** @return array<string, SchemaKey> */
    private static function projectKeys(): array
    {
        return [
            'name' => self::leaf(self::text(...)),
            'type' => self::leaf(self::enum(Config::PROJECT_TYPES)),
            'repo' => SchemaKey::map([
                'path' => self::leaf(self::text(...)),
                'primary_branch' => self::leaf(self::text(...)),
                'primary_remote' => self::leaf(self::text(...)),
            ]),
            'worktrees_root' => self::leaf(self::text(...)),
            'issue_tracker' => SchemaKey::map([
                'provider' => self::leaf(self::enum(Config::PROVIDERS)),
                'identity' => self::leaf(self::text(...)),
                'project_key' => self::leaf(self::text(...)),
                'repo' => self::leaf(self::text(...)),
                'site' => self::leaf(self::text(...)),
            ]),
            'confluence' => SchemaKey::map([
                'space' => self::leaf(self::text(...)),
            ]),
            'startup_script' => self::leaf(self::text(...)),
            // Not config.yaml's `models` (by project type): the project's own
            // per-agent models, its default being its default_model.
            'models' => SchemaKey::map(
                array_fill_keys(Agent::modelAgents(), self::leaf(self::text(...))),
                closed: 'agent',
                mapping: 'a mapping of agent to model',
            ),
        ] + self::inherited(true);
    }

    /** @param \Closure(mixed): ?string $check */
    private static function leaf(\Closure $check, bool $deferred = false): SchemaKey
    {
        return SchemaKey::leaf($check, $deferred);
    }

    /** A scalar written as text: a string, or a bare number YAML read as one. */
    private static function text(mixed $value): ?string
    {
        return \is_string($value) || \is_int($value) || \is_float($value) ? null : 'must be a string, got '.self::show($value);
    }

    /**
     * A models entry: a model, or a mapping of agent label
     * (or `default`, the model of the type's other agents) to model.
     */
    private static function typeModel(mixed $value): ?string
    {
        if (null === self::text($value)) {
            return null;
        }
        if (!\is_array($value) || ([] !== $value && array_is_list($value))) {
            return 'must be a model, or a mapping of agent to model, got '.self::show($value);
        }
        $keys = [Config::MODEL_DEFAULT_KEY, ...Agent::modelAgents()];
        foreach ($value as $agent => $model) {
            $agent = (string) $agent;
            if (!\in_array($agent, $keys, true)) {
                return \sprintf('has unknown agent %s (expected one of: %s)', var_export($agent, true), implode(', ', $keys));
            }
            $problem = null !== $model ? self::text($model) : null;
            if (null !== $problem) {
                return "{$agent} {$problem}";
            }
        }

        return null;
    }

    private static function flag(mixed $value): ?string
    {
        return \is_bool($value) ? null : 'must be true or false, got '.self::show($value);
    }

    /** @return \Closure(mixed): ?string */
    private static function positiveInt(string $unit): \Closure
    {
        return static function (mixed $value) use ($unit): ?string {
            $whole = \is_int($value) || (\is_string($value) && 1 === preg_match('/^\d+$/', $value));

            return $whole && (int) $value >= 1 ? null : "must be a whole number of {$unit}, at least 1, got ".self::show($value);
        };
    }

    /** @return \Closure(mixed): ?string */
    private static function intRange(int $min, int $max, string $unit): \Closure
    {
        return static function (mixed $value) use ($min, $max, $unit): ?string {
            $whole = \is_int($value) || (\is_string($value) && 1 === preg_match('/^\d+$/', $value));

            return $whole && (int) $value >= $min && (int) $value <= $max ? null : "must be a whole number of {$unit}, from {$min} to {$max}, got ".self::show($value);
        };
    }

    /**
     * @param list<string> $allowed
     *
     * @return \Closure(mixed): ?string
     */
    private static function enum(array $allowed): \Closure
    {
        return static fn (mixed $value): ?string => \is_string($value) && \in_array($value, $allowed, true)
            ? null
            : \sprintf('must be one of: %s, got %s', implode(', ', $allowed), self::show($value));
    }

    /** @return \Closure(mixed): ?string */
    private static function stringList(string $noun): \Closure
    {
        return static function (mixed $value) use ($noun): ?string {
            if (!\is_array($value) || !array_is_list($value)) {
                return "must be a list of {$noun} (e.g. [a, b]), got ".self::show($value);
            }
            foreach ($value as $item) {
                if (null !== self::text($item)) {
                    return "must be a list of {$noun}, got an item ".self::show($item);
                }
            }

            return null;
        };
    }

    private static function states(mixed $value): ?string
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return 'must be a list of states, got '.self::show($value);
        }
        foreach ($value as $raw) {
            if (!\is_string($raw) || null === State::tryFrom($raw)) {
                return \sprintf('has unknown state %s (expected one of %s)', var_export($raw, true), implode(', ', array_map(static fn (State $s): string => $s->value, State::cases())));
            }
        }

        return null;
    }

    private static function hosts(mixed $value): ?string
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return 'must be a list of host names, got '.self::show($value);
        }
        foreach ($value as $host) {
            if (!\is_string($host) || null === TrustedHosts::normalize($host)) {
                return self::show($host).' is not a host name or IP address (no scheme, port or wildcard)';
            }
        }

        return null;
    }

    private static function directories(mixed $value): ?string
    {
        if (\is_string($value)) {
            return null;
        }
        if (\is_array($value) && array_is_list($value) && [] === array_filter($value, static fn (mixed $dir): bool => !\is_string($dir))) {
            return null;
        }

        return 'must be a list of directories (or a colon-separated string), got '.self::show($value);
    }

    /** A value as it appears in a message. */
    private static function show(mixed $value): string
    {
        return match (true) {
            \is_array($value) => [] === $value || array_is_list($value) ? 'a list' : 'a mapping',
            \is_bool($value) => $value ? 'true' : 'false',
            null === $value => 'null',
            \is_scalar($value) => var_export($value, true),
            default => get_debug_type($value),
        };
    }
}
