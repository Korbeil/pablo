<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Config\GlobalConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Doctor\Doctor;
use Pablo\Poller\Poller;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\FileLock;
use Pablo\Support\PabloError;

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
 *
 * Polls of different projects may overlap (dispatch.poll_concurrency, see
 * runConcurrently()); everything else runs one job at a time, in this process.
 */
final class Dispatch
{
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

    /**
     * The one job run for several projects at once (dispatch.poll_concurrency):
     * it only reads GitHub and the trackers and writes each project's own
     * task records. sync and main write to git repositories (rebase, push,
     * reset of the primary checkout) and stay one at a time.
     */
    public const CONCURRENT_JOB = 'poll';

    /**
     * Projects polled at once by default. GitHub asks API clients to make
     * requests serially and penalises concurrent ones and bursts (secondary
     * rate limits), and every concurrent poll is one more `gh` caller on the
     * same token (plus the parallel `gh pr list`s a poll already makes): 2
     * halves the wall time at most twice the request rate of a sequential
     * run, and never more requests in total. 1 polls one project at a time.
     */
    public const DEFAULT_POLL_CONCURRENCY = 2;

    /** @var resource|null test seam: redirect stderr for tests */
    public $stderr;

    public function __construct(
        private readonly Doctor $doctor,
        private readonly Sync $sync,
        private readonly Poller $poller,
        private readonly AgentLauncherFactory $agentLaunchers,
        private readonly Stamps $stamps,
        private readonly GitRepoInterface $git,
        private readonly WorkerPool $workers,
        private readonly GlobalConfig $globalConfig = new GlobalConfig(),
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
            if ($this->doctor->isSoft($result->cli)) {
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
        try {
            $fd = FileLock::acquire(self::lockPath(), 0.0);
        } catch (PabloError) {
            return false; // an unopenable lock is reported as "not acquired"
        }
        if (null === $fd) {
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
        FileLock::release($this->lockFd);
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
        $report = $this->git->updatePrimary($cfg->repo->path, $cfg->repo->primaryBranch, $cfg->repo->primaryRemote);
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
            'sync' => static fn (ProjectConfig $cfg) => $cfg->sync->intervalMinutes,
            'poll' => static fn (ProjectConfig $cfg) => $cfg->statePolling->intervalMinutes,
            'main' => static fn (ProjectConfig $cfg) => $cfg->mainUpdate->intervalMinutes,
        ];
    }

    private function isDue(ProjectConfig $cfg, string $job, float $now): bool
    {
        if ('main' === $job && !$cfg->mainUpdate->enabled) {
            return false;
        }
        $last = $this->stamps->readStamp($cfg->name, $job);
        if (null === $last) {
            return true;
        }

        return $now - $last->ranAt >= self::jobIntervals()[$job]($cfg) * 60;
    }

    /** Due and not backing off (says so when it is). */
    private function shouldRun(ProjectConfig $cfg, string $job, float $now): bool
    {
        if (!$this->isDue($cfg, $job, $now)) {
            return false;
        }
        $retryAt = $this->health->retryAt($this->health->read($cfg->name, $job), self::jobIntervals()[$job]($cfg));
        if (null !== $retryAt && $now < $retryAt) {
            echo "[{$cfg->name}] {$job}: backing off after repeated failures, next try after ".DispatchHealth::iso($retryAt)."\n";

            return false;
        }

        return true;
    }

    /**
     * Run one job in this process and record it.
     *
     * @param callable(ProjectConfig, Store): mixed $runner
     *
     * @return bool whether it failed
     */
    private function runInProcess(ProjectConfig $cfg, string $job, callable $runner, Store $store, float $now): bool
    {
        $jobStartedAt = microtime(true);
        try {
            $result = $runner($cfg, $store);
        } catch (\Throwable $e) {
            fwrite($this->stderr ?? \STDERR, "pablo dispatch: {$job} failed for project {$cfg->name}:\n{$e}\n");
            $this->health->recordFailure($cfg->name, $job, $now, $e->getMessage());

            return true; // stamp not written: retried next tick (or after the back-off)
        }
        $this->recordSuccess($cfg->name, $job, $now, \is_int($result) ? $result : 0, microtime(true) - $jobStartedAt);

        return false;
    }

    private function recordSuccess(string $project, string $job, float $now, int $checkFailures, float $durationS): void
    {
        $this->health->recordSuccess($project, $job, $now, $checkFailures);
        $this->stamps->write($project, $job, $now, $durationS);
    }

    /**
     * dispatch.poll_concurrency, else DEFAULT_POLL_CONCURRENCY. An invalid
     * value never stops the background job: it is reported and the run polls
     * one project at a time, as before the setting existed.
     */
    private function pollConcurrency(): int
    {
        try {
            return $this->globalConfig->dispatchPollConcurrency(self::DEFAULT_POLL_CONCURRENCY);
        } catch (PabloError $e) {
            fwrite($this->stderr ?? \STDERR, "pablo dispatch: {$e->getMessage()}; polling one project at a time\n");

            return 1;
        }
    }

    /**
     * The jobs before `poll` (sync) for every project, one after the other;
     * then every due poll, up to $concurrency projects at a time, each in a
     * child process (WorkerPool); then the jobs after it (main), one after the
     * other. A project's own jobs keep their order and never overlap; only
     * polls of different projects overlap, never two sharing a repository.
     *
     * @param array<string, ProjectConfig>                         $projects
     * @param array<string, callable(ProjectConfig, Store): mixed> $runners
     *
     * @return bool whether a job failed
     */
    private function runConcurrently(array $projects, Store $store, array $runners, float $now, int $concurrency): bool
    {
        $failed = false;
        $jobs = array_keys($runners);
        $at = (int) array_search(self::CONCURRENT_JOB, $jobs, true);
        $before = \array_slice($runners, 0, $at, true);
        $after = \array_slice($runners, $at + 1, null, true);

        foreach ($projects as $cfg) {
            foreach ($before as $job => $runner) {
                if ($this->shouldRun($cfg, $job, $now)) {
                    $failed = $this->runInProcess($cfg, $job, $runner, $store, $now) || $failed;
                }
            }
        }

        $queue = [];
        foreach ($projects as $cfg) {
            if ($this->shouldRun($cfg, self::CONCURRENT_JOB, $now)) {
                $queue[$cfg->name] = $cfg;
            }
        }
        if (1 === \count($queue)) {
            // Nothing to overlap with: no child process needed.
            $failed = $this->runInProcess(reset($queue), self::CONCURRENT_JOB, $runners[self::CONCURRENT_JOB], $store, $now) || $failed;
        } elseif ([] !== $queue) {
            $failed = $this->runPooled(array_values($queue), $now, $concurrency) || $failed;
        }

        foreach ($projects as $cfg) {
            foreach ($after as $job => $runner) {
                if ($this->shouldRun($cfg, $job, $now)) {
                    $failed = $this->runInProcess($cfg, $job, $runner, $store, $now) || $failed;
                }
            }
        }

        return $failed;
    }

    /**
     * @param list<ProjectConfig> $projects
     *
     * @return bool whether a job failed
     */
    private function runPooled(array $projects, float $now, int $concurrency): bool
    {
        $failed = false;
        $slowedDown = false;
        // Keyed by the real repository path: two projects on one repository never poll at once.
        $jobs = array_map(static fn (ProjectConfig $cfg): PoolJob => new PoolJob(
            $cfg->name,
            self::CONCURRENT_JOB,
            realpath($cfg->repo->path) ?: $cfg->repo->path,
        ), $projects);

        $left = $this->workers->run($jobs, $concurrency, function (WorkerOutcome $outcome) use ($now, &$failed, &$slowedDown): bool {
            $failed = $this->finishPooled($outcome, $now) || $failed;
            if ($slowedDown || !RateLimitSignal::in($outcome)) {
                return false;
            }
            // Rate limited: no more concurrent callers on that token this run.
            // The remaining projects still run, one at a time (what the
            // sequential dispatcher did); the limited one backs off as usual.
            $slowedDown = true;
            echo "pablo dispatch: {$outcome->job->project} {$outcome->job->job} looks rate limited; the remaining projects are polled one at a time this run\n";

            return true;
        });

        foreach ($left as $job) {
            $failed = true;
            fwrite($this->stderr ?? \STDERR, "pablo dispatch: {$job->job} not started for project {$job->project}: the run took too long, it is retried next tick\n");
        }

        return $failed;
    }

    /**
     * Print a child's output whole, attributed to its project, and record it
     * exactly as runInProcess() would have.
     *
     * @return bool whether it failed
     */
    private function finishPooled(WorkerOutcome $outcome, float $now): bool
    {
        $project = $outcome->job->project;
        $job = $outcome->job->job;
        if ('' !== $outcome->output) {
            echo str_ends_with($outcome->output, "\n") ? $outcome->output : $outcome->output."\n";
        }
        $stderr = rtrim($outcome->errorOutput);

        if ($outcome->ok()) {
            if ('' !== $stderr) {
                fwrite($this->stderr ?? \STDERR, (string) preg_replace('/^/m', "[{$project}] ", $stderr)."\n");
            }
            $this->recordSuccess($project, $job, $now, $outcome->result->checkFailures ?? 0, $outcome->durationS);

            return false;
        }

        $detail = null !== $outcome->failure ? $outcome->failure.('' !== $stderr ? "\n".$stderr : '') : ('' !== $stderr ? $stderr : $outcome->error());
        fwrite($this->stderr ?? \STDERR, "pablo dispatch: {$job} failed for project {$project}:\n{$detail}\n");
        $this->health->recordFailure($project, $job, $now, $outcome->error());

        return true; // stamp not written: retried next tick (or after the back-off)
    }

    /**
     * $configErrors are the project files that failed to load
     * (Config::projectErrors()): logged every run, the valid projects still run.
     *
     * A runner may return an int: the per-task checks that failed during an
     * otherwise successful run (recorded, never a job failure).
     *
     * With a poll concurrency above 1 and several polls due, the polls run in
     * child processes (`internal:dispatch-job`, over defaultRunners()), not
     * through $runners['poll'].
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
            $concurrency = isset($runners[self::CONCURRENT_JOB]) && \count($projects) > 1 ? $this->pollConcurrency() : 1;
            if ($concurrency > 1) {
                $failed = $this->runConcurrently($projects, $store, $runners, $now, $concurrency) || $failed;
            } else {
                foreach ($projects as $cfgValue) {
                    foreach ($runners as $job => $runner) {
                        if ($this->shouldRun($cfgValue, $job, $now)) {
                            $failed = $this->runInProcess($cfgValue, $job, $runner, $store, $now) || $failed;
                        }
                    }
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
