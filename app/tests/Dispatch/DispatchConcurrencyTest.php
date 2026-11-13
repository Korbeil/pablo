<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Config\GlobalConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Dispatch\Stamps;
use Pablo\Dispatch\WorkerPool;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeJobWorkers;
use Pablo\Tests\FakeJobWorkerScript;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

/**
 * The dispatcher's concurrent polls (dispatch.poll_concurrency): bounded,
 * isolated, attributed, recorded exactly as the sequential dispatcher does,
 * and back to one at a time on a rate limit. No child process is ever
 * started: FakeJobWorkers scripts them.
 */
final class DispatchConcurrencyTest extends TestCase
{
    use UsesGlobalConfig;

    private const NOW = 1_800_000_000.0;

    private string $tmp;
    private FakeJobWorkers $workers;
    private float $clock = 0.0;

    /** @var list<string> sync/main runs and worker starts, in order */
    private array $log = [];

    /** @var resource */
    private $stderr;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('dispatch-concurrency');
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
        $this->workers = new FakeJobWorkers();
        $stderr = fopen('php://memory', 'w+');
        \assert(false !== $stderr);
        $this->stderr = $stderr;
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        $this->unsetGlobalConfig();
    }

    private function dispatch(int $concurrency, float $jobTimeoutS = WorkerPool::JOB_TIMEOUT_S, float $startDeadlineS = WorkerPool::START_DEADLINE_S): Dispatch
    {
        $this->writeGlobalConfig("dispatch:\n  poll_concurrency: {$concurrency}\n");

        return $this->dispatchWithConfig($jobTimeoutS, $startDeadlineS);
    }

    private function dispatchWithConfig(float $jobTimeoutS = WorkerPool::JOB_TIMEOUT_S, float $startDeadlineS = WorkerPool::START_DEADLINE_S): Dispatch
    {
        $runner = new FakeProcessRunner();
        $runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(0, 'ok');
        $global = new GlobalConfig();
        $doctor = new Doctor($runner, \Pablo\Tests\Registries::runtimes($global), \Pablo\Tests\Registries::ades($global), static fn (string $name): string => '/usr/bin/'.$name);
        $time = new \Pablo\Domain\Time();
        $git = new \Pablo\Tests\FakeGit();
        $gh = new \Pablo\Tests\FakeGhPr();
        $repoSlug = new \Pablo\Support\RepoSlug($git);
        $providers = new \Pablo\Tests\StubProviders();
        $sm = new \Pablo\StateMachine\StateMachine($gh, $providers, $repoSlug, $time, \Pablo\Tests\Registries::hooks());
        $sync = new \Pablo\Provider\Git\Sync($git, $gh, $repoSlug, $time, \Pablo\Tests\Registries::runtimes($global));
        $poller = new \Pablo\Poller\Poller($gh, $git, $providers, $repoSlug, $sm, $time, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($runner), \Pablo\Tests\Registries::hooks());
        // Each clock read is one second later: timeouts and deadlines without sleeping.
        $pool = new WorkerPool(
            $this->workers,
            $jobTimeoutS,
            $startDeadlineS,
            fn (): float => $this->clock += 1.0,
            static function (): void {},
        );
        $dispatch = new Dispatch($doctor, $sync, $poller, \Pablo\Tests\Registries::launchers($runner, $global), new Stamps(), $git, $pool, $global);
        $dispatch->stderr = $this->stderr;

        return $dispatch;
    }

    private function cfg(string $name, ?string $repo = null): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repo: new RepoConfig(path: $this->tmp.'/'.($repo ?? $name), primaryBranch: 'main', worktreesRoot: $this->tmp.'/wt/'.$name),
            tracker: new TrackerConfig(provider: 'github', identity: 'octocat', projectKey: 'PR'),
            sync: new SyncConfig(strategy: 'rebase', autoApply: false, intervalMinutes: 30),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    /**
     * @param list<string> $names
     *
     * @return array<string, ProjectConfig>
     */
    private function projects(array $names): array
    {
        $projects = [];
        foreach ($names as $name) {
            $projects[$name] = $this->cfg($name);
        }

        return $projects;
    }

    /**
     * sync and main log their runs; poll is the in-process runner (only used
     * when the polls are not pooled) and returns $checkFailures[project].
     *
     * @param array<string, int> $checkFailures
     *
     * @return array<string, callable(ProjectConfig, Store): mixed>
     */
    private function runners(array $checkFailures = []): array
    {
        return [
            'sync' => function (ProjectConfig $c): void { $this->log[] = "sync {$c->name}"; },
            'poll' => function (ProjectConfig $c) use ($checkFailures): int {
                $this->log[] = "poll {$c->name} in-process";
                echo "[{$c->name}] polled\n";

                return $checkFailures[$c->name] ?? 0;
            },
            'main' => function (ProjectConfig $c): void { $this->log[] = "main {$c->name}"; },
        ];
    }

    /** @param \Closure(string): FakeJobWorkerScript $script per project */
    private function script(\Closure $script): void
    {
        $this->workers->script = function (string $project, string $job) use ($script): FakeJobWorkerScript {
            $this->log[] = "start {$project} {$job}";

            return $script($project);
        };
    }

    /**
     * @param array<string, ProjectConfig>                         $projects
     * @param array<string, callable(ProjectConfig, Store): mixed> $runners
     *
     * @return array{0: int, 1: string, 2: string} exit code, stdout, stderr
     */
    private function runDispatch(Dispatch $dispatch, array $projects, ?array $runners = null): array
    {
        ob_start();
        try {
            $rc = $dispatch->run($projects, new Store($this->tmp.'/state'), $runners ?? $this->runners(), self::NOW);
        } finally {
            $stdout = (string) ob_get_clean();
        }
        rewind($this->stderr);

        return [$rc, $stdout, (string) stream_get_contents($this->stderr)];
    }

    public function testNeverMoreThanTheLimitAlive(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok("[{$p}] polled\n", ticks: 3));

        [$rc] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b', 'c', 'd', 'e']));

        $this->assertSame(0, $rc);
        $this->assertSame(['a:poll', 'b:poll', 'c:poll', 'd:poll', 'e:poll'], $this->workers->started, 'every due poll, in project order');
        $this->assertSame(2, $this->workers->maxAlive);
        foreach (['a', 'b', 'c', 'd', 'e'] as $p) {
            $this->assertNotNull((new Stamps())->readStamp($p, 'poll'), "{$p} stamped");
        }
    }

    public function testALimitOfThreeUsesThree(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok(ticks: 4));

        $this->runDispatch($this->dispatch(3), $this->projects(['a', 'b', 'c', 'd', 'e']));

        $this->assertSame(3, $this->workers->maxAlive);
    }

    public function testALimitOfOneIsTheSequentialDispatcher(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok());

        [$rc, $stdout] = $this->runDispatch($this->dispatch(1), $this->projects(['a', 'b']));

        $this->assertSame(0, $rc);
        $this->assertSame([], $this->workers->started, 'no child process');
        $this->assertSame(['sync a', 'poll a in-process', 'main a', 'sync b', 'poll b in-process', 'main b'], $this->log);
        $this->assertSame("[a] polled\n[b] polled\n", $stdout);
    }

    public function testTheDefaultIsTwo(): void
    {
        $this->writeGlobalConfig("{}\n");
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok(ticks: 3));

        $this->runDispatch($this->dispatchWithConfig(), $this->projects(['a', 'b', 'c']));

        $this->assertSame(Dispatch::DEFAULT_POLL_CONCURRENCY, $this->workers->maxAlive);
        $this->assertSame(2, Dispatch::DEFAULT_POLL_CONCURRENCY);
    }

    public function testAnInvalidLimitFallsBackToOneAtATimeAndSaysSo(): void
    {
        $this->writeGlobalConfig("dispatch:\n  poll_concurrency: 50\n");
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok());

        [$rc, , $stderr] = $this->runDispatch($this->dispatchWithConfig(), $this->projects(['a', 'b']));

        $this->assertSame(0, $rc, 'a bad value never stops the background job');
        $this->assertSame([], $this->workers->started);
        $this->assertStringContainsString('dispatch.poll_concurrency must be a whole number of projects, from 1 to 8, got 50; polling one project at a time', $stderr);
    }

    public function testSyncRunsBeforeThePollsAndMainAfterOneProjectsJobsNeverOverlap(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok(ticks: 2));

        $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b', 'c']));

        $this->assertSame(['sync a', 'sync b', 'sync c'], \array_slice($this->log, 0, 3), 'every sync first, one at a time');
        $this->assertSame(['main a', 'main b', 'main c'], \array_slice($this->log, -3), 'every main last, once every poll has ended');
        $this->assertSame(0, $this->workers->alive);
        $this->assertSame(['start a poll', 'start b poll', 'start c poll'], \array_slice($this->log, 3, 3));
    }

    public function testASinglePollDueRunsInProcess(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok());
        (new Stamps())->write('b', 'poll', self::NOW - 60, 1.0); // b polled a minute ago: not due

        $this->runDispatch($this->dispatch(4), $this->projects(['a', 'b']));

        $this->assertSame([], $this->workers->started);
        $this->assertContains('poll a in-process', $this->log);
    }

    public function testOneProjectsFailureNeverBlocksTheOthers(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => 'b' === $p
            ? FakeJobWorkers::failing('gh failed: HTTP 502', "[b] wk-1: started\n")
            : FakeJobWorkers::ok("[{$p}] polled\n", ticks: 2));

        [$rc, $stdout, $stderr] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b', 'c']));

        $this->assertSame(1, $rc);
        $this->assertNull((new Stamps())->readStamp('b', 'poll'), 'no stamp on failure');
        $this->assertNotNull((new Stamps())->readStamp('a', 'poll'));
        $this->assertNotNull((new Stamps())->readStamp('c', 'poll'));
        $health = (new DispatchHealth())->read('b', 'poll');
        $this->assertNotNull($health);
        $this->assertSame(1, $health->consecutiveFailures);
        $this->assertSame('gh failed: HTTP 502', $health->lastError);
        $this->assertStringContainsString("pablo dispatch: poll failed for project b:\nPablo\\Support\\PabloError: gh failed: HTTP 502\n#0 trace\n", $stderr);
        $this->assertStringContainsString("[b] wk-1: started\n", $stdout, "a failed child's output is still printed");
        $this->assertSame(DispatchHealth::OUTCOME_FAILED, (new DispatchHealth())->lastDispatch()?->outcome);
    }

    public function testAHungChildIsKilledAndReportedAsItsProjectsFailure(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => 'a' === $p
            ? new FakeJobWorkerScript(null, '', '', null)
            : FakeJobWorkers::ok(ticks: 2));

        [$rc, , $stderr] = $this->runDispatch($this->dispatch(2, jobTimeoutS: 30.0), $this->projects(['a', 'b', 'c']));

        $this->assertSame(1, $rc);
        $this->assertSame(['a:poll'], $this->workers->stopped);
        $this->assertSame(0, $this->workers->alive, 'nothing left running once the run is over');
        $this->assertNull((new Stamps())->readStamp('a', 'poll'));
        $this->assertSame('poll worker timed out after 30s and was killed', (new DispatchHealth())->read('a', 'poll')?->lastError);
        $this->assertStringContainsString("pablo dispatch: poll failed for project a:\npoll worker timed out after 30s and was killed\n", $stderr);
        $this->assertNotNull((new Stamps())->readStamp('b', 'poll'));
        $this->assertNotNull((new Stamps())->readStamp('c', 'poll'));
        $this->assertSame(['main a', 'main b', 'main c'], \array_slice($this->log, -3), 'the run goes on after the kill');
    }

    public function testACrashedChildIsReportedWithItsExitCode(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => 'a' === $p
            ? new FakeJobWorkerScript(1, "[a] wk-1: started\n", "PHP Fatal error:  Allowed memory size exhausted\n", 255)
            : FakeJobWorkers::ok());

        [$rc] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b']));

        $this->assertSame(1, $rc);
        $this->assertSame('poll worker exited with code 255 without a result: PHP Fatal error: Allowed memory size exhausted', (new DispatchHealth())->read('a', 'poll')?->lastError);
    }

    public function testAChildThatCannotStartFailsOnlyItsProject(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => 'a' === $p
            ? new FakeJobWorkerScript(1, '', '', 0, startError: 'cannot start the poll worker for a: no such file')
            : FakeJobWorkers::ok());

        [$rc] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b']));

        $this->assertSame(1, $rc);
        $this->assertSame('cannot start the poll worker for a: no such file', (new DispatchHealth())->read('a', 'poll')?->lastError);
        $this->assertNotNull((new Stamps())->readStamp('b', 'poll'));
    }

    public function testEachChildsOutputComesOutWholeInTheOrderTheyEnd(): void
    {
        // b ends first: its block comes first, and no block is split by another.
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok("[{$p}] wk-1: draft → ci-red\n[{$p}] wk-2: ci-red → ready-to-review\n", ticks: 'b' === $p ? 1 : 5));

        [, $stdout] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b']));

        $this->assertSame(
            "[b] wk-1: draft → ci-red\n[b] wk-2: ci-red → ready-to-review\n"
            ."[a] wk-1: draft → ci-red\n[a] wk-2: ci-red → ready-to-review\n",
            $stdout,
        );
    }

    public function testAChildsStderrOnSuccessIsAttributedLineByLine(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => new FakeJobWorkerScript(1, (new \Pablo\Dispatch\JobResult(null))->line(), "Deprecated: x\nin y\n", 0));

        [, , $stderr] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b']));

        $this->assertStringContainsString("[a] Deprecated: x\n[a] in y\n", $stderr);
        $this->assertStringContainsString("[b] Deprecated: x\n[b] in y\n", $stderr);
    }

    public function testStampsAndHealthAreThoseOfTheSequentialDispatcher(): void
    {
        $checks = ['a' => 0, 'b' => 2, 'c' => 0];
        $projects = $this->projects(['a', 'b', 'c']);
        $pollFailingC = fn (ProjectConfig $c, Store $s): int => 'c' === $c->name ? throw new \Pablo\Support\PabloError('gh failed: HTTP 502') : $this->runners($checks)['poll']($c, $s);

        // Sequential, in-process.
        $this->runDispatch($this->dispatch(1), $projects, ['poll' => $pollFailingC]);
        $sequential = $this->recorded(['a', 'b', 'c']);

        // Pooled, from a clean slate.
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps-pooled');
        $this->script(static fn (string $p): FakeJobWorkerScript => 'c' === $p
            ? FakeJobWorkers::failing('gh failed: HTTP 502')
            : FakeJobWorkers::ok(ticks: 2, checkFailures: $checks[$p]));
        $this->runDispatch($this->dispatch(3), $projects, ['poll' => $pollFailingC]);
        $pooled = $this->recorded(['a', 'b', 'c']);

        $this->assertSame(['a:poll', 'b:poll', 'c:poll'], $this->workers->started);
        $this->assertSame($sequential, $pooled);
        $this->assertSame(2, $pooled['b']['health']['check_failures'] ?? null);
        $this->assertNull($pooled['c']['stamp']);
    }

    /**
     * Health files as written, and each stamp's ran_at (its duration is wall time).
     *
     * @param list<string> $names
     *
     * @return array<string, array{health: mixed, stamp: ?float}>
     */
    private function recorded(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            $out[$name] = [
                'health' => json_decode((string) file_get_contents(DispatchHealth::jobPath($name, 'poll')), true),
                'stamp' => (new Stamps())->readStamp($name, 'poll')?->ranAt,
            ];
        }

        return $out;
    }

    public function testARateLimitedProjectFailsAloneAndTheRestRunOneAtATime(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => 'a' === $p
            ? FakeJobWorkers::failing('gh failed: You have exceeded a secondary rate limit')
            : FakeJobWorkers::ok(ticks: 3));

        [$rc, $stdout] = $this->runDispatch($this->dispatch(3), $this->projects(['a', 'b', 'c', 'd', 'e']));

        $this->assertSame(1, $rc);
        $this->assertSame('gh failed: You have exceeded a secondary rate limit', (new DispatchHealth())->read('a', 'poll')?->lastError);
        $this->assertSame(['a:poll', 'b:poll', 'c:poll', 'd:poll', 'e:poll'], $this->workers->started, 'the others still run');
        foreach (['b', 'c', 'd', 'e'] as $p) {
            $this->assertNotNull((new Stamps())->readStamp($p, 'poll'));
        }
        $this->assertStringContainsString('pablo dispatch: a poll looks rate limited; the remaining projects are polled one at a time this run', $stdout);
        // Every child started after a's end is alone.
        $timeline = $this->workers->timeline;
        $afterLimit = \array_slice($timeline, (int) array_search(['end a', 2], $timeline, true) + 1);
        $starts = array_values(array_filter($afterLimit, static fn (array $e): bool => str_starts_with($e[0], 'start ')));
        $this->assertSame([['start d', 1], ['start e', 1]], $starts);
    }

    public function testARateLimitInATaskCheckAlsoSlowsTheRunDown(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => 'a' === $p
            ? FakeJobWorkers::ok("[a] wk-1: poll check failed: gh failed: API rate limit exceeded for user ID 1.\n", checkFailures: 1)
            : FakeJobWorkers::ok(ticks: 3));

        [$rc, $stdout] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b', 'c', 'd']));

        $this->assertSame(0, $rc, 'the job itself worked');
        $this->assertSame(1, (new DispatchHealth())->read('a', 'poll')?->checkFailures);
        $this->assertStringContainsString('looks rate limited', $stdout);
        $this->assertSame(['start d', 1], array_values(array_filter($this->workers->timeline, static fn (array $e): bool => 'start d' === $e[0]))[0]);
    }

    public function testProjectsSharingARepositoryNeverPollAtOnce(): void
    {
        $projects = ['a' => $this->cfg('a', 'shared'), 'b' => $this->cfg('b', 'shared'), 'c' => $this->cfg('c')];
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok(ticks: 3));

        $this->runDispatch($this->dispatch(3), $projects);

        $this->assertSame(['a:poll', 'c:poll', 'b:poll'], $this->workers->started, 'b waits for a, c does not');
        $aEnd = array_search(['end a', 1], $this->workers->timeline, true);
        $bStart = array_search('start b', array_column($this->workers->timeline, 0), true);
        $this->assertIsInt($aEnd);
        $this->assertGreaterThan($aEnd, $bStart);
    }

    public function testJobsLeftWhenTheDeadlinePassesAreRetriedNextTick(): void
    {
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok(ticks: 20));

        [$rc, , $stderr] = $this->runDispatch($this->dispatch(2, startDeadlineS: 10.0), $this->projects(['a', 'b', 'c']));

        $this->assertSame(1, $rc);
        $this->assertSame(['a:poll', 'b:poll'], $this->workers->started);
        $this->assertNull((new Stamps())->readStamp('c', 'poll'), 'not run: still due next tick');
        $this->assertNull((new DispatchHealth())->read('c', 'poll'), 'and not a failure of c');
        $this->assertStringContainsString('pablo dispatch: poll not started for project c: the run took too long, it is retried next tick', $stderr);
    }

    public function testBackingOffPollsAreNotQueued(): void
    {
        $health = new DispatchHealth();
        $health->recordFailure('b', 'poll', self::NOW - 60, 'x');
        $health->recordFailure('b', 'poll', self::NOW - 60, 'x');
        $this->script(static fn (string $p): FakeJobWorkerScript => FakeJobWorkers::ok());

        [, $stdout] = $this->runDispatch($this->dispatch(2), $this->projects(['a', 'b', 'c']));

        $this->assertSame(['a:poll', 'c:poll'], $this->workers->started);
        $this->assertStringContainsString('[b] poll: backing off after repeated failures', $stdout);
    }
}
