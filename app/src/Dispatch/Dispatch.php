<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Config\ProjectConfig;
use Pablo\Doctor\Doctor;
use Pablo\Poller\Poller;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\Files;

/**
 * The cron dispatcher, fired by the pablo-dispatch systemd user timer.
 *
 * One timer, every 5 minutes; per-project cadence lives in the configs and is
 * enforced here via last-run stamps. A global flock prevents overlapping
 * runs; a hard preflight failure (gh, the agent runtime's CLI, an impossible
 * backend/runtime pair) aborts the whole run fast and
 * loud, while a soft one (provider CLIs) only warns.
 *
 * Every run and every job's outcome is recorded in DispatchHealth (read by
 * system:doctor and the dashboard); a job failing BACKOFF_AFTER times in a
 * row backs off exponentially instead of being retried on every tick.
 */
final class Dispatch
{
    /**
     * Provider CLIs that are soft requirements: a failure here warns but does
     * not abort the run — only the affected project's sync/poll degrades (the
     * per-project failure path already handles that). So are the ADEs' CLIs
     * (agents fall back to a headless run, see Doctor::isSoft()); gh and the
     * selected runtime's CLI stay hard.
     */
    private const SOFT_CLIS = ['acli', 'acli-confluence', 'linear'];

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
        private readonly GitRepoInterface $git,
        private readonly DispatchHealth $health = new DispatchHealth(),
    ) {
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public function preflightErrors(array $projects): array
    {
        // An impossible agent setup (an ADE that cannot run the runtime) would only
        // fail every launch: stop here, loud, before touching any project.
        $errors = $this->doctor->configErrors();
        foreach ($this->doctor->checkAll($projects) as $result) {
            if ($result->ok()) {
                continue;
            }
            // Soft requirements: warn and continue. An ADE's launcher falls
            // back to a headless run; a dead acli/linear only degrades the
            // projects that use that provider (they fail per-project at
            // runtime), it never blocks the whole run.
            if (\in_array($result->cli, self::SOFT_CLIS, true) || $this->doctor->isSoft($result->cli)) {
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
    /**
     * The global dispatch lock. `pablo update` holds it too, so no dispatch
     * run overlaps a binary swap (a tick that finds it taken skips).
     */
    public static function lockPath(): string
    {
        return Stamps::stampsDir().'/dispatch.lock';
    }

    private function tryDispatchLock(): bool
    {
        $path = self::lockPath();
        $dir = \dirname($path);
        // best-effort: an unopenable lock is reported as "not acquired" below.
        Files::tryEnsureDir($dir);
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

    /** @return int the per-task checks that failed (recorded in the poll job's health, not a job failure) */
    public function runPoll(ProjectConfig $cfg, Store $store): int
    {
        $agents = $this->agentLaunchers->create();
        foreach ($this->poller->pollProject($cfg, $store, $agents) as $event) {
            echo "[{$cfg->name}] {$event}\n";
        }

        return $this->poller->lastCheckFailures();
    }

    public function runMainUpdate(ProjectConfig $cfg, Store $store): void
    {
        $report = $this->git->updatePrimary($cfg->repoPath, $cfg->primaryBranch, $cfg->primaryRemote);
        echo "[{$cfg->name}] main: {$report->render()}\n";
    }

    /** @return array<string, callable(ProjectConfig, Store): mixed> */
    public function defaultRunners(): array
    {
        return [
            'sync' => $this->runSync(...),
            'poll' => $this->runPoll(...),
            'main' => $this->runMainUpdate(...),
        ];
    }

    /** @return array<string, callable(ProjectConfig): int> */
    public static function jobIntervals(): array
    {
        return [
            'sync' => static fn (ProjectConfig $cfg) => $cfg->syncInterval,
            'poll' => static fn (ProjectConfig $cfg) => $cfg->pollInterval,
            'main' => static fn (ProjectConfig $cfg) => $cfg->mainUpdateInterval,
        ];
    }

    private function isDue(ProjectConfig $cfg, string $job, float $now): bool
    {
        if ('main' === $job && !$cfg->mainUpdateEnabled) {
            return false;
        }
        $last = $this->stamps->readStamp($cfg->name, $job);
        if (null === $last) {
            return true;
        }

        return $now - $last->ranAt >= self::jobIntervals()[$job]($cfg) * 60;
    }

    /**
     * $configErrors are the project files that failed to load
     * (Config::projectErrors()): logged every run, the valid projects still run.
     *
     * A runner may return an int: the per-task checks that failed during an
     * otherwise successful run (recorded, never a job failure).
     *
     * @param array<string, ProjectConfig>                              $projects
     * @param array<string, callable(ProjectConfig, Store): mixed>|null $runners
     * @param array<string, string>                                     $configErrors
     */
    public function run(array $projects, Store $store, ?array $runners = null, ?float $now = null, array $configErrors = []): int
    {
        $runners ??= $this->defaultRunners();
        $now ??= microtime(true);

        if (!$this->tryDispatchLock()) {
            echo "pablo dispatch: already running, nothing to do\n";

            return 0;
        }
        $this->health->startDispatch($now);
        try {
            // A broken project file only drops that project: say so loudly on
            // every run (until fixed), then dispatch the others.
            foreach ($configErrors as $file => $error) {
                fwrite($this->stderr ?? \STDERR, "pablo dispatch: skipping invalid project config {$file}: {$error}\n");
            }

            $errors = $this->preflightErrors($projects);
            if ([] !== $errors) {
                fwrite($this->stderr ?? \STDERR, "pablo dispatch: CLI preflight failed, aborting:\n"
                    .implode("\n", array_map(static fn ($e) => "  ❌ {$e}", $errors))."\n");
                $this->health->finishDispatch($now, microtime(true), DispatchHealth::OUTCOME_PREFLIGHT_FAILED, implode('; ', $errors));

                return 1;
            }

            $failed = [] !== $configErrors;
            foreach ($projects as $cfgValue) {
                foreach ($runners as $job => $runner) {
                    if (!$this->isDue($cfgValue, $job, $now)) {
                        continue;
                    }
                    $retryAt = $this->health->retryAt(
                        $this->health->read($cfgValue->name, $job),
                        self::jobIntervals()[$job]($cfgValue),
                    );
                    if (null !== $retryAt && $now < $retryAt) {
                        echo "[{$cfgValue->name}] {$job}: backing off after repeated failures, next try after ".DispatchHealth::iso($retryAt)."\n";
                        continue;
                    }
                    $jobStartedAt = microtime(true);
                    try {
                        $result = $runner($cfgValue, $store);
                    } catch (\Throwable $e) {
                        $failed = true;
                        fwrite($this->stderr ?? \STDERR, "pablo dispatch: {$job} failed for project {$cfgValue->name}:\n{$e}\n");
                        $this->health->recordFailure($cfgValue->name, $job, $now, $e->getMessage());
                        continue; // stamp not written: retried next tick (or after the back-off)
                    }
                    $this->health->recordSuccess($cfgValue->name, $job, $now, \is_int($result) ? $result : 0);
                    $durationS = microtime(true) - $jobStartedAt;
                    $stamp = Stamps::stampsDir()."/{$cfgValue->name}.{$job}";
                    $dir = \dirname($stamp);
                    // best-effort: a missing stamp only re-runs the job next time.
                    Files::tryEnsureDir($dir);
                    file_put_contents($stamp, $this->stamps->encodeStamp($now, $durationS));
                }
            }

            // A run whose only failure is a broken project file still names it.
            $detail = [] !== $configErrors ? 'invalid project config: '.implode(', ', array_map(static fn ($f): string => basename((string) $f), array_keys($configErrors))) : null;
            $this->health->finishDispatch($now, microtime(true), $failed ? DispatchHealth::OUTCOME_FAILED : DispatchHealth::OUTCOME_OK, $detail);

            return $failed ? 1 : 0;
        } finally {
            $this->releaseDispatchLock();
        }
    }
}
