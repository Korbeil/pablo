<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Agents\AgentLauncherFactory;
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
 * runs; a hard preflight failure (gh/opencode) aborts the whole run fast and
 * loud, while a soft one (provider CLIs) only warns.
 */
final class Dispatch
{
    /**
     * Provider CLIs that are soft requirements: a failure here warns but does
     * not abort the run — only the affected project's sync/poll degrades (the
     * per-project failure path already handles that). gh/opencode stay hard.
     */
    private const SOFT_CLIS = ['orca', 'openchamber', 'acli', 'acli-confluence', 'linear'];

    /**
     * How often the scheduler fires this dispatcher. Per-project cadence is
     * enforced by the stamps below, so a project can never be polled more often
     * than this — which is why the dashboard rounds its "next poll" estimate up
     * to the next tick.
     *
     * Must match systemd/pablo-dispatch.timer (OnCalendar=*:0/5) and
     * launchd/com.pablo.dispatch.plist.template (StartInterval 300).
     */
    public const TICK_MINUTES = 5;

    /** @var resource|null test seam: redirect stderr for tests */
    public $stderr;

    public function __construct(
        private readonly Doctor $doctor,
        private readonly Sync $sync,
        private readonly Poller $poller,
        private readonly AgentLauncherFactory $agentLaunchers,
        private readonly Stamps $stamps,
    ) {
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public function preflightErrors(array $projects): array
    {
        $errors = [];
        foreach ($this->doctor->checkAll($projects) as $result) {
            if ($result->ok()) {
                continue;
            }
            // Provider CLIs are soft requirements: warn and continue. orca's
            // agent runner falls back to headless opencode; a dead acli/linear
            // only degrades the projects that use that provider (they fail
            // per-project at runtime), it never blocks the whole run.
            if (\in_array($result->cli, self::SOFT_CLIS, true)) {
                echo "pablo dispatch: warning: {$result->cli} not usable ({$result->detail}); affected projects only\n";
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
    private function tryDispatchLock(): bool
    {
        $path = Stamps::stampsDir().'/dispatch.lock';
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $fd = fopen($path, 'c+');
        if (false === $fd) {
            return false;
        }
        if (!flock($fd, \LOCK_EX | \LOCK_NB)) {
            fclose($fd);

            return false;
        }

        // Keep the fd open for the duration of the run; released on close.
        $this->lockFd = $fd;

        return true;
    }

    /** @var resource|null */
    private $lockFd;

    public function releaseDispatchLock(): void
    {
        if (\is_resource($this->lockFd)) {
            fclose($this->lockFd);
        }
        $this->lockFd = null;
    }

    public function runSync(ProjectConfig $cfg, Store $store): void
    {
        $agents = $this->agentLaunchers->create();
        $reports = $this->sync->syncProject($cfg, $store, null, $agents);
        echo "[{$cfg->name}] sync:\n".Sync::renderReports($reports)."\n";
    }

    public function runPoll(ProjectConfig $cfg, Store $store): void
    {
        $agents = $this->agentLaunchers->create();
        foreach ($this->poller->pollProject($cfg, $store, $agents) as $event) {
            echo "[{$cfg->name}] {$event}\n";
        }
    }

    /** @return array<string, callable(ProjectConfig, Store): void> */
    public function defaultRunners(): array
    {
        return [
            'sync' => $this->runSync(...),
            'poll' => $this->runPoll(...),
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

    private function isDue(ProjectConfig $cfg, string $job, float $now): bool
    {
        $last = $this->stamps->readStamp($cfg->name, $job);
        if (null === $last) {
            return true;
        }

        return $now - $last->ranAt >= self::jobIntervals()[$job]($cfg) * 60;
    }

    /**
     * @param array<string, ProjectConfig>                             $projects
     * @param array<string, callable(ProjectConfig, Store): void>|null $runners
     */
    public function run(array $projects, Store $store, ?array $runners = null, ?float $now = null): int
    {
        $runners ??= $this->defaultRunners();
        $now ??= microtime(true);

        if (!$this->tryDispatchLock()) {
            echo "pablo dispatch: already running, nothing to do\n";

            return 0;
        }
        try {
            $errors = $this->preflightErrors($projects);
            if ([] !== $errors) {
                fwrite($this->stderr ?? \STDERR, "pablo dispatch: CLI preflight failed, aborting:\n"
                    .implode("\n", array_map(static fn ($e) => "  ❌ {$e}", $errors))."\n");

                return 1;
            }

            $failed = false;
            foreach ($projects as $cfgValue) {
                foreach ($runners as $job => $runner) {
                    if (!$this->isDue($cfgValue, $job, $now)) {
                        continue;
                    }
                    $jobStartedAt = microtime(true);
                    try {
                        $runner($cfgValue, $store);
                    } catch (\Throwable $e) {
                        $failed = true;
                        fwrite($this->stderr ?? \STDERR, "pablo dispatch: {$job} failed for project {$cfgValue->name}:\n{$e}\n");
                        continue; // stamp not written: retried next tick
                    }
                    $durationS = microtime(true) - $jobStartedAt;
                    $stamp = Stamps::stampsDir()."/{$cfgValue->name}.{$job}";
                    $dir = \dirname($stamp);
                    if (!is_dir($dir)) {
                        @mkdir($dir, 0o777, true);
                    }
                    file_put_contents($stamp, $this->stamps->encodeStamp($now, $durationS));
                }
            }

            return $failed ? 1 : 0;
        } finally {
            $this->releaseDispatchLock();
        }
    }
}
