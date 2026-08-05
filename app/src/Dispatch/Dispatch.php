<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Agents\Agents;
use Pablo\Config\ProjectConfig;
use Pablo\Doctor\Doctor;
use Pablo\Poller\Poller;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;

/**
 * The cron dispatcher, fired by the pablo-dispatch systemd user timer.
 *
 * One timer, every 5 minutes; per-project cadence lives in the configs and is
 * enforced here via last-run stamps. A global flock prevents overlapping
 * runs; a failed CLI preflight aborts the whole run fast and loud.
 */
final class Dispatch
{
    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    /** @var callable|null test seam: (array): list<string> */
    private static $preflightErrors;

    public static function setPreflightErrors(?callable $fn): void
    {
        self::$preflightErrors = $fn;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public static function preflightErrors(array $projects): array
    {
        if (null !== self::$preflightErrors) {
            return (self::$preflightErrors)($projects);
        }
        $errors = [];
        foreach (Doctor::checkAll($projects) as $result) {
            if ($result->ok()) {
                continue;
            }
            // orca is a soft requirement: the agent runner falls back to
            // headless opencode when orca is unusable.
            if ('orca' === $result->cli) {
                echo "pablo dispatch: warning: orca not usable ({$result->detail}); agent runs will use the headless fallback\n";
                continue;
            }
            $errors[] = "{$result->cli}: {$result->detail} ({$result->hint})";
        }

        return $errors;
    }

    /**
     * Non-blocking global dispatch lock (PHP has no yield-based context
     * manager, so this returns whether it was acquired rather than a bool-
     * yielding generator).
     */
    private static function tryDispatchLock(): bool
    {
        $path = self::stampsDir().'/dispatch.lock';
        @mkdir(\dirname($path), 0o777, true);
        $fd = fopen($path, 'c+');
        if (false === $fd) {
            return false;
        }
        if (!flock($fd, \LOCK_EX | \LOCK_NB)) {
            fclose($fd);

            return false;
        }

        // Keep the fd open for the duration of the run; released on close.
        self::$lockFd = $fd;

        return true;
    }

    /** @var resource|null */
    private static $lockFd;

    public static function releaseDispatchLock(): void
    {
        if (\is_resource(self::$lockFd)) {
            fclose(self::$lockFd);
        }
        self::$lockFd = null;
    }

    public static function runSync(ProjectConfig $cfg, Store $store): void
    {
        $reports = Sync::syncProject($cfg, $store, null);
        echo "[{$cfg->name}] sync:\n".Sync::renderReports($reports)."\n";
    }

    public static function runPoll(ProjectConfig $cfg, Store $store): void
    {
        $agents = new Agents(static::shimPath());
        foreach (Poller::pollProject($cfg, $store, $agents) as $event) {
            echo "[{$cfg->name}] {$event}\n";
        }
    }

    public static function shimPath(): string
    {
        $env = getenv('PABLO_SHIM');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/.local/bin/pablo';
    }

    /** @return array<string, callable(ProjectConfig, Store): void> */
    public static function defaultRunners(): array
    {
        return [
            'sync' => [self::class, 'runSync'],
            'poll' => [self::class, 'runPoll'],
        ];
    }

    /** @return array<string, callable(ProjectConfig): int> */
    public static function jobIntervals(): array
    {
        return [
            'sync' => static fn (ProjectConfig $cfg) => $cfg->syncInterval,
            'poll' => static fn (ProjectConfig $cfg) => $cfg->pollInterval,
        ];
    }

    public static function stampsDir(): string
    {
        $override = getenv('PABLO_STAMPS_DIR');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return (getenv('HOME') ?: '~').'/.pablo/stamps';
    }

    private static function isDue(ProjectConfig $cfg, string $job, float $now): bool
    {
        $stamp = self::stampsDir()."/{$cfg->name}.{$job}";
        if (!is_file($stamp)) {
            return true;
        }
        $last = (float) trim((string) file_get_contents($stamp));

        return $now - $last >= self::jobIntervals()[$job]($cfg) * 60;
    }

    /**
     * @param array<string, ProjectConfig>                             $projects
     * @param array<string, callable(ProjectConfig, Store): void>|null $runners
     */
    public static function run(array $projects, Store $store, ?array $runners = null, ?float $now = null): int
    {
        $runners ??= self::defaultRunners();
        $now ??= microtime(true);

        if (!self::tryDispatchLock()) {
            echo "pablo dispatch: already running, nothing to do\n";

            return 0;
        }
        try {
            $errors = self::preflightErrors($projects);
            if ([] !== $errors) {
                fwrite(\STDERR, "pablo dispatch: CLI preflight failed, aborting:\n"
                    .implode("\n", array_map(static fn ($e) => "  ❌ {$e}", $errors))."\n");

                return 1;
            }

            $failed = false;
            $syncedRepos = [];
            foreach ($projects as $cfgValue) {
                foreach ($runners as $job => $runner) {
                    if (!self::isDue($cfgValue, $job, $now)) {
                        continue;
                    }
                    $skip = false;
                    if ('sync' === $job) {
                        $repoKey = realpath($cfgValue->repoPath) ?: $cfgValue->repoPath;
                        if (isset($syncedRepos[$repoKey])) {
                            echo "[{$cfgValue->name}] sync skipped: repo {$cfgValue->repoPath} already synced in this run\n";
                            $skip = true;
                        } else {
                            $syncedRepos[$repoKey] = true;
                        }
                    }
                    if (!$skip) {
                        try {
                            $runner($cfgValue, $store);
                        } catch (\Throwable $e) {
                            $failed = true;
                            fwrite(\STDERR, "pablo dispatch: {$job} failed for project {$cfgValue->name}:\n{$e}\n");
                            continue; // stamp not written: retried next tick
                        }
                    }
                    $stamp = self::stampsDir()."/{$cfgValue->name}.{$job}";
                    @mkdir(\dirname($stamp), 0o777, true);
                    file_put_contents($stamp, (string) $now);
                }
            }

            return $failed ? 1 : 0;
        } finally {
            self::releaseDispatchLock();
        }
    }
}
