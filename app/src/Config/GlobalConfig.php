<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Web\TrustedHosts;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * PABLO-wide (cross-project) configuration.
 *
 * Lives at ~/.pablo/config.yaml and is resolved at runtime — never at
 * container compile time — so the PABLO_CONFIG override and HOME are read
 * fresh on every call (same reason Store resolves its root in its
 * constructor). Holds the global defaults the project loader falls back to
 * per key.
 *
 * Standalone on purpose: Config depends on this, so this must not depend
 * back on Config.
 */
final class GlobalConfig
{
    public function configPath(?string $override = null): string
    {
        if (null !== $override && '' !== $override) {
            return $override;
        }

        return PabloPaths::pabloPath('config.yaml', 'PABLO_CONFIG');
    }

    /**
     * Sections every reader may index without checking: a missing (or null)
     * one reads as this.
     */
    private const SECTIONS = [
        'sync' => [],
        'state_polling' => [],
        'main_update' => [],
        'review' => [],
        'ci' => [],
        'testing' => ['enabled' => true],
        'hooks' => [],
        'claude_code' => [],
        'scheduler' => [],
    ];

    /** Keys read as strings: a bare number YAML read as one is cast. */
    private const TEXT_KEYS = ['default_model', 'pr_description_locale', 'branch_prefix', 'agent_backend', 'agent_runtime'];

    /**
     * The file's keys, the project loader's per-key defaults. A missing file
     * reads as an empty one, so projects can still rely on their own explicit
     * values.
     *
     * @return array<string, mixed>
     */
    public function defaults(?string $path = null): array
    {
        $path ??= $this->configPath();
        $data = [];
        if (is_file($path)) {
            try {
                $data = Yaml::parseFile($path);
            } catch (ParseException $e) {
                throw new PabloError(basename($path).': invalid YAML: '.$e->getMessage());
            }
            if (!\is_array($data)) {
                throw new PabloError(basename($path).': expected a mapping at top level');
            }

            // A bad value of a key every project inherits would fail them all:
            // fatal here. Unknown keys are ignored (validate and the doctor
            // report them), and global-only keys are refused by the command
            // reading them (see ConfigSchema).
            $error = ConfigSchema::loadError($data, ConfigSchema::GLOBAL, $path, 'config.yaml');
            if (null !== $error) {
                throw new PabloError($error->text());
            }
        }
        foreach (self::SECTIONS as $key => $empty) {
            if (!\is_array($data[$key] ?? null)) {
                $data[$key] = $empty;
            }
        }
        foreach (self::TEXT_KEYS as $key) {
            if (isset($data[$key])) {
                $data[$key] = (string) $data[$key];
            }
        }

        return $data;
    }

    /**
     * `prune.keep_days`: how many days of logs `system:prune` keeps (agent
     * logs, service log archives, other versions' caches), else $default.
     * Read per call, like every other key.
     *
     * @throws PabloError when it is not a whole number of days, at least 1
     */
    public function pruneKeepDays(int $default): int
    {
        $prune = $this->defaults()['prune'] ?? null;
        $this->check('prune', $prune);
        $days = \is_array($prune) ? ($prune['keep_days'] ?? null) : null;
        if (null === $days) {
            return $default;
        }
        $this->check('prune.keep_days', $days);

        return (int) $days;
    }

    /**
     * The extra Host names the dashboard answers besides 127.0.0.1 and
     * localhost (dashboard_trusted_hosts), normalized, empty when unset.
     * Read per call, like every other key.
     *
     * @return list<string>
     *
     * @throws PabloError when the value is not a list of host names
     */
    public function dashboardTrustedHosts(): array
    {
        $hosts = $this->defaults()['dashboard_trusted_hosts'] ?? null;
        $this->check('dashboard_trusted_hosts', $hosts);
        $normalized = [];
        foreach (\is_array($hosts) ? $hosts : [] as $host) {
            $normalized[(string) TrustedHosts::normalize((string) $host)] = true;
        }

        return array_keys($normalized);
    }

    /**
     * `scheduler.path`: extra directories `system:install` puts first on the
     * background services' PATH — a list, or one colon-separated string.
     *
     * @return list<string>
     *
     * @throws PabloError when it is neither
     */
    public function schedulerPath(): array
    {
        $scheduler = $this->defaults()['scheduler'];
        $value = \is_array($scheduler) ? ($scheduler['path'] ?? null) : null;
        if (null === $value) {
            return [];
        }
        $this->check('scheduler.path', $value);
        $dirs = \is_string($value) ? explode(':', $value) : (array) $value;

        return array_values(array_filter(array_map(static fn (mixed $dir): string => trim((string) $dir), $dirs), static fn (string $dir): bool => '' !== $dir));
    }

    /**
     * The port of the dashboard (`pablo web`, and its service): dashboard_port,
     * else $default. Read per call, like every other key.
     *
     * @throws PabloError when dashboard_port is not a port number
     */
    public function dashboardPort(int $default): int
    {
        $port = $this->defaults()['dashboard_port'] ?? null;
        if (null === $port) {
            return $default;
        }
        $this->check('dashboard_port', $port);

        return (int) $port;
    }

    /** The most projects `dispatch.poll_concurrency` may poll at once. */
    public const MAX_POLL_CONCURRENCY = 8;

    /**
     * `dispatch.poll_concurrency`: how many projects the background dispatcher
     * polls at once (1 = one after the other), else $default. Read per call.
     *
     * @throws PabloError when it is not a whole number from 1 to MAX_POLL_CONCURRENCY
     */
    public function dispatchPollConcurrency(int $default): int
    {
        $dispatch = $this->defaults()['dispatch'] ?? null;
        $this->check('dispatch', $dispatch);
        $value = \is_array($dispatch) ? ($dispatch['poll_concurrency'] ?? null) : null;
        if (null === $value) {
            return $default;
        }
        $this->check('dispatch.poll_concurrency', $value);

        return (int) $value;
    }

    /**
     * Whether `pablo system:install` installs the shell completion: on unless
     * shell_completion is false (what --without-completion records, so a
     * `pablo update` keeps the choice).
     */
    public function shellCompletion(): bool
    {
        return !\in_array($this->defaults()['shell_completion'] ?? null, [false, 'false'], true);
    }

    /**
     * A deferred key's value, checked by the schema (same wording as
     * validate and the doctor).
     *
     * @throws PabloError when it is invalid
     */
    private function check(string $keyPath, mixed $value): void
    {
        $problem = ConfigSchema::valueError(ConfigSchema::GLOBAL, $keyPath, $value);
        if (null !== $problem) {
            throw new PabloError("config.yaml: {$keyPath} {$problem}");
        }
    }

    /** A TCP port: an integer (or digits) between 1 and 65535. */
    public static function isPort(mixed $value): bool
    {
        if (\is_int($value)) {
            return $value >= 1 && $value <= 65535;
        }

        return \is_string($value) && 1 === preg_match('/^\d+$/', $value) && (int) $value >= 1 && (int) $value <= 65535;
    }
}
