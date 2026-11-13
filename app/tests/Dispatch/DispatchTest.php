<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Config\MainUpdateConfig;
use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class DispatchTest extends TestCase
{
    use \Pablo\Tests\UsesGlobalConfig;

    private string $tmp;
    private string $stamps;
    private Dispatch $dispatch;
    private \Pablo\Tests\FakeProcessRunner $runner;
    private \Pablo\Tests\FakeGit $git;

    /** @var list<array{0: string, 1: string}> */
    private array $ran;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('dispatch');
        $this->stamps = $this->tmp.'/stamps';
        putenv('PABLO_STAMPS_DIR='.$this->stamps);
        // An empty global config: the developer's real ~/.pablo/config.yaml
        // (e.g. agent_backend: openchamber) must not leak into the preflight.
        // poll_concurrency: 1 — these tests pin the sequential dispatcher;
        // DispatchConcurrencyTest covers the pooled polls.
        $this->writeGlobalConfig("dispatch:\n  poll_concurrency: 1\n");
        $this->ran = [];
        $this->runner = new \Pablo\Tests\FakeProcessRunner();
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
        $global = new \Pablo\Config\GlobalConfig();
        $doctor = new Doctor($this->runner, \Pablo\Tests\Registries::runtimes($global), \Pablo\Tests\Registries::ades($global), static fn (string $name): string => '/usr/bin/'.$name);
        $time = new \Pablo\Domain\Time();
        $git = $this->git = new \Pablo\Tests\FakeGit();
        $gh = new \Pablo\Tests\FakeGhPr();
        $repoSlug = new \Pablo\Support\RepoSlug($git);
        $providers = new \Pablo\Tests\StubProviders();
        $sm = new \Pablo\StateMachine\StateMachine($gh, $providers, $repoSlug, $time, \Pablo\Tests\Registries::hooks());
        $sync = new \Pablo\Provider\Git\Sync($git, $gh, $repoSlug, $time, \Pablo\Tests\Registries::runtimes($global));
        $poller = new \Pablo\Poller\Poller($gh, $git, $providers, $repoSlug, $sm, $time, new \Pablo\Agents\Runtime\OpenCode\OpenCodeUsage($this->runner), \Pablo\Tests\Registries::hooks());
        $stamps = new \Pablo\Dispatch\Stamps();
        $this->dispatch = new Dispatch($doctor, $sync, $poller, \Pablo\Tests\Registries::launchers($this->runner, $global), $stamps, $git, new \Pablo\Dispatch\WorkerPool(new \Pablo\Tests\FakeJobWorkers()), $global);
        $fh = fopen('/dev/null', 'w');
        \assert(false !== $fh);
        $this->dispatch->stderr = $fh;
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        $this->unsetGlobalConfig();
    }

    private function cfg(string $name, string $provider = 'github'): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repo: new RepoConfig(
                path: $this->tmp.'/'.$name,
                primaryBranch: 'main',
                worktreesRoot: $this->tmp.'/wt/'.$name,
            ),
            tracker: new TrackerConfig(
                provider: $provider,
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    /** @param array<string, callable(ProjectConfig, Store): mixed>|null $runners */
    /** @param array<string, callable(ProjectConfig, Store): mixed>|null $runners
     * @return array{projects: array<string, ProjectConfig>, store: Store, runners: array<string, callable>}
     */
    private function env(?array $runners = null): array
    {
        $projects = ['a' => $this->cfg('a'), 'b' => $this->cfg('b')];
        $store = new Store($this->tmp.'/state');
        $runners ??= [
            'sync' => function (ProjectConfig $c, Store $s): void { $this->ran[] = ['sync', $c->name]; },
            'poll' => function (ProjectConfig $c, Store $s): void { $this->ran[] = ['poll', $c->name]; },
        ];

        return ['projects' => $projects, 'store' => $store, 'runners' => $runners];
    }

    /** @param array<string, ProjectConfig> $projects
     * @param array<string, callable(ProjectConfig, Store): mixed> $runners
     */
    private function runDispatch(array $projects, Store $store, array $runners, ?float $now = null): int
    {
        return $this->dispatch->run($projects, $store, $runners, $now);
    }

    private function stampAge(string $name, string $job, int $secondsAge): void
    {
        (new \Pablo\Dispatch\Stamps())->write($name, $job, microtime(true) - $secondsAge, 0.0);
    }

    public function testFirstRunRunsEverything(): void
    {
        $env = $this->env();
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertSame(0, $rc);
        $pairs = array_map(static fn ($p) => $p[1].':'.$p[0], $this->ran);
        sort($pairs);
        $this->assertSame(['a:poll', 'a:sync', 'b:poll', 'b:sync'], $pairs);
    }

    public function testStampRecordsRunDurationPerJob(): void
    {
        $env = $this->env();
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);

        $stamp = (new \Pablo\Dispatch\Stamps())->readStamp('a', 'poll');
        $this->assertNotNull($stamp);
        $this->assertGreaterThanOrEqual(0.0, $stamp->ranAt);
        $this->assertGreaterThanOrEqual(0.0, (float) $stamp->durationS);
    }

    public function testFreshStampsSkipJobs(): void
    {
        $env = $this->env();
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->ran = [];
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertSame(0, $rc);
        $this->assertSame([], $this->ran);
    }

    public function testStaleStampRerunsJob(): void
    {
        $env = $this->env();
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->ran = [];
        $this->stampAge('a', 'poll', 11 * 60);
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertSame([['poll', 'a']], $this->ran);
    }

    public function testOneProjectFailureDoesNotBlockOthers(): void
    {
        $env = $this->env();
        $env['runners']['sync'] = static function (): void {
            throw new \RuntimeException('provider exploded');
        };
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertSame(1, $rc);
        $names = array_map(static fn ($p) => $p[0], $this->ran);
        $this->assertContains('poll', $names);
    }

    public function testBrokenProjectFileDoesNotBlockTheValidOne(): void
    {
        $dir = $this->tmp.'/projects';
        mkdir($dir);
        file_put_contents($dir.'/good.yaml', <<<YAML
name: good
type: work
repo:
  path: {$this->tmp}/good
  primary_branch: main
issue_tracker:
  provider: github
  identity: octocat
  project_key: GO
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
default_model: opencode-go/glm-5.3-flash
pr_description_locale: en

YAML);
        file_put_contents($dir.'/bad.yaml', "name: bad\ntype: [unclosed\n");
        $config = new \Pablo\Config\Config(new \Pablo\Config\GlobalConfig());
        $err = fopen('php://memory', 'w+');
        \assert(false !== $err);
        $this->dispatch->stderr = $err;

        $env = $this->env();
        $rc = $this->dispatch->run($config->loadProjects($dir), $env['store'], $env['runners'], null, $config->projectErrors($dir));

        $this->assertSame(1, $rc, 'a broken project file fails the run (loud), after the valid ones ran');
        $pairs = array_map(static fn ($p) => $p[1].':'.$p[0], $this->ran);
        sort($pairs);
        $this->assertSame(['good:poll', 'good:sync'], $pairs);
        $this->assertFileExists($this->stamps.'/good.poll');
        rewind($err);
        $this->assertStringContainsString("pablo dispatch: skipping invalid project config {$dir}/bad.yaml: bad.yaml: invalid YAML", (string) stream_get_contents($err));

        // The health record says why the run failed, though no job did.
        $last = (new DispatchHealth())->lastDispatch();
        $this->assertNotNull($last);
        $this->assertSame(DispatchHealth::OUTCOME_FAILED, $last->outcome);
        $this->assertSame('invalid project config: bad.yaml', $last->detail);
    }

    public function testFailedJobDoesNotWriteStamp(): void
    {
        $env = $this->env();
        $env['runners']['sync'] = static function (): void {
            throw new \RuntimeException('nope');
        };
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertFileDoesNotExist($this->stamps.'/a.sync');
        $this->assertFileExists($this->stamps.'/a.poll');
    }

    public function testSecondDispatcherExitsQuietly(): void
    {
        $env = $this->env();
        $lockPath = $this->stamps.'/dispatch.lock';
        @mkdir(\dirname($lockPath), 0o777, true);
        $fd = fopen($lockPath, 'c+');
        if (false === $fd) {
            $this->fail('cannot open lock');
        }
        flock($fd, \LOCK_EX | \LOCK_NB);
        ob_start();
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $out = (string) ob_get_clean();
        flock($fd, \LOCK_UN);
        fclose($fd);
        $this->assertSame(0, $rc);
        $this->assertSame([], $this->ran);
        $this->assertStringContainsString('already running', $out);
    }

    public function testPreflightOrcaFailureIsSoft(): void
    {
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'orca' === $argv[0]
            ? new \Pablo\Support\ProbeResult(1, 'timed out after 30s')
            : new \Pablo\Support\ProbeResult(0, 'ok');
        ob_start();
        $errors = $this->dispatch->preflightErrors([]);
        $out = (string) ob_get_clean();
        $this->assertSame([], $errors);
        $this->assertStringContainsString('warning: orca not usable', $out);
    }

    public function testPreflightProviderCliFailuresAreSoft(): void
    {
        // Provider CLIs are only checked when a project uses them; none do
        // here, so nothing soft can appear at all.
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
        ob_start();
        $errors = $this->dispatch->preflightErrors([]);
        $out = (string) ob_get_clean();
        $this->assertSame([], $errors);
        $this->assertStringNotContainsString('acli', $out);
        $this->assertStringNotContainsString('linear', $out);
    }

    public function testPreflightRefusesOpenChamberWithClaudeCode(): void
    {
        $this->writeGlobalConfig("agent_runtime: claude-code\nagent_backend: openchamber\n");
        $errors = $this->dispatch->preflightErrors([]);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $errors[0]);
    }

    public function testPreflightRefusesOpenChamberWithCopilot(): void
    {
        $this->writeGlobalConfig("agent_runtime: copilot\nagent_backend: openchamber\n");
        $errors = $this->dispatch->preflightErrors([]);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('agent_runtime copilot cannot run on agent_backend openchamber', $errors[0]);
    }

    public function testPreflightMissingClaudeIsHard(): void
    {
        $this->writeGlobalConfig("agent_runtime: claude-code\n");
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'claude' === $argv[0]
            ? new \Pablo\Support\ProbeResult(1, 'Not logged in')
            : new \Pablo\Support\ProbeResult(0, 'ok');
        ob_start();
        $errors = $this->dispatch->preflightErrors([]);
        ob_end_clean();
        $this->assertCount(1, $errors);
        $this->assertStringStartsWith('claude: Not logged in', $errors[0]);
    }

    public function testPreflightGhFailureIsHard(): void
    {
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'gh' === $argv[0]
            ? new \Pablo\Support\ProbeResult(1, 'not logged in')
            : new \Pablo\Support\ProbeResult(0, 'ok');
        $errors = $this->dispatch->preflightErrors([]);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('gh', $errors[0]);
    }

    public function testPreflightFailureAborts(): void
    {
        $env = $this->env();
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'gh' === $argv[0]
            ? new \Pablo\Support\ProbeResult(1, 'not authenticated')
            : new \Pablo\Support\ProbeResult(0, 'ok');
        ob_start();
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        ob_end_clean();
        $this->assertSame(1, $rc);
        $this->assertSame([], $this->ran);
    }

    public function testMainJobRunsOnItsOwnCadence(): void
    {
        $env = $this->env();
        $env['runners']['main'] = function (ProjectConfig $c, Store $s): void { $this->ran[] = ['main', $c->name]; };
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertFileExists($this->stamps.'/a.main');

        $this->ran = [];
        $this->stampAge('a', 'main', 59 * 60);
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertSame([], $this->ran);

        $this->stampAge('a', 'main', 61 * 60);
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        $this->assertSame([['main', 'a']], $this->ran);
    }

    public function testMainJobSkipsDisabledProject(): void
    {
        $base = $this->cfg('a');
        $disabled = new ProjectConfig(
            name: 'a',
            type: 'work',
            repo: new RepoConfig(
                path: $base->repo->path,
                primaryBranch: 'main',
                worktreesRoot: $base->repo->worktreesRoot,
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
            mainUpdate: new MainUpdateConfig(
                enabled: false,
            ),
        );
        $runners = ['main' => function (ProjectConfig $c, Store $s): void { $this->ran[] = ['main', $c->name]; }];
        $this->runDispatch(['a' => $disabled, 'b' => $this->cfg('b')], new Store($this->tmp.'/state'), $runners);
        $this->assertSame([['main', 'b']], $this->ran);
    }

    public function testRunMainUpdateUsesPrimaryRemoteAndBranch(): void
    {
        $base = $this->cfg('a');
        $fork = new ProjectConfig(
            name: 'a',
            type: 'work',
            repo: new RepoConfig(
                path: $base->repo->path,
                primaryBranch: 'develop',
                worktreesRoot: $base->repo->worktreesRoot,
                primaryRemote: 'upstream',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
        ob_start();
        $this->dispatch->runMainUpdate($fork, new Store($this->tmp.'/state'));
        $out = (string) ob_get_clean();
        $this->assertSame([[$base->repo->path, 'develop', 'upstream']], $this->git->primaryUpdates);
        $this->assertStringContainsString('[a] main: = up-to-date develop', $out);
    }

    public function testSameRepoSyncsEachProjectsTasks(): void
    {
        $sharedRepo = $this->tmp.'/shared-repo';
        mkdir($sharedRepo, 0o777, true);

        $a = new ProjectConfig(
            name: 'a',
            type: 'work',
            repo: new RepoConfig(
                path: $sharedRepo,
                primaryBranch: 'main',
                worktreesRoot: $this->tmp.'/wt/a',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
        $b = new ProjectConfig(
            name: 'b',
            type: 'work',
            repo: new RepoConfig(
                path: $sharedRepo,
                primaryBranch: 'main',
                worktreesRoot: $this->tmp.'/wt/b',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PB',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );

        $store = new Store($this->tmp.'/state');
        $runners = [
            'sync' => function (ProjectConfig $c, Store $_): void { $this->ran[] = ['sync', $c->name]; },
            'poll' => function (ProjectConfig $c, Store $_): void { $this->ran[] = ['poll', $c->name]; },
        ];

        ob_start();
        $rc = $this->dispatch->run(['a' => $a, 'b' => $b], $store, $runners);
        ob_end_clean();
        $this->assertSame(0, $rc);

        // Projects sharing a repo each sync their own tasks; neither is skipped.
        $pairs = array_map(static fn ($p) => $p[1].':'.$p[0], $this->ran);
        sort($pairs);
        $this->assertSame(['a:poll', 'a:sync', 'b:poll', 'b:sync'], $pairs);
    }

    private function syncFailures(): int
    {
        $health = (new DispatchHealth())->read('a', 'sync');
        $this->assertNotNull($health);

        return $health->consecutiveFailures;
    }

    private function failingSync(string $message = 'gh: HTTP 502'): \Closure
    {
        return function (ProjectConfig $c, Store $s) use ($message): void {
            $this->ran[] = ['sync', $c->name];
            throw new \RuntimeException($message);
        };
    }

    public function testFailureIsRecordedInJobHealth(): void
    {
        $env = $this->env();
        $env['runners']['sync'] = $this->failingSync("gh: HTTP 502\nmore lines");
        $now = 1_800_000_000.0;
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);

        $health = new DispatchHealth();
        $sync = $health->read('a', 'sync');
        $this->assertNotNull($sync);
        $this->assertSame(1, $sync->consecutiveFailures);
        $this->assertSame('gh: HTTP 502 more lines', $sync->lastError);
        $this->assertSame('2027-01-15T08:00:00+00:00', $sync->lastAttemptAt);
        $this->assertSame('2027-01-15T08:00:00+00:00', $sync->failingSince);
        $this->assertNull($sync->lastSuccessAt);

        $poll = $health->read('a', 'poll');
        $this->assertNotNull($poll);
        $this->assertFalse($poll->failing());
        $this->assertSame('2027-01-15T08:00:00+00:00', $poll->lastSuccessAt);

        $last = $health->lastDispatch();
        $this->assertNotNull($last);
        $this->assertSame(DispatchHealth::OUTCOME_FAILED, $last->outcome);
        $this->assertNotNull($last->finishedAt);
    }

    public function testRepeatedFailuresBackOff(): void
    {
        $env = $this->env();
        $env['runners']['sync'] = $this->failingSync();
        $now = 1_800_000_000.0;

        // Two failures in a row (one tick apart): retried, now backing off.
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 300);
        $this->assertSame(2, $this->syncFailures());

        // 5 min × 2^(2-1) = 10 min after the last attempt.
        $this->ran = [];
        ob_start();
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 600);
        $out = (string) ob_get_clean();
        $this->assertNotContains(['sync', 'a'], $this->ran);
        $this->assertStringContainsString('[a] sync: backing off', $out);

        $this->ran = [];
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 900);
        $this->assertContains(['sync', 'a'], $this->ran);
        $this->assertSame(3, $this->syncFailures());
    }

    public function testSuccessResetsFailureStreak(): void
    {
        $env = $this->env();
        $env['runners']['sync'] = $this->failingSync();
        $now = 1_800_000_000.0;
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 300);

        $env['runners']['sync'] = function (ProjectConfig $c, Store $s): void { $this->ran[] = ['sync', $c->name]; };
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 900);

        $sync = (new DispatchHealth())->read('a', 'sync');
        $this->assertNotNull($sync);
        $this->assertSame(0, $sync->consecutiveFailures);
        $this->assertNull($sync->lastError);
        $this->assertNull($sync->failingSince);
        $this->assertSame([], (new DispatchHealth())->failing($env['projects']));
        $this->assertSame(DispatchHealth::OUTCOME_OK, (new DispatchHealth())->lastDispatch()?->outcome);
    }

    public function testPollCheckFailuresAreRecordedButNotAJobFailure(): void
    {
        $env = $this->env();
        $env['runners']['poll'] = static fn (ProjectConfig $c, Store $s): int => 2;
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);

        $this->assertSame(0, $rc);
        $poll = (new DispatchHealth())->read('a', 'poll');
        $this->assertNotNull($poll);
        $this->assertFalse($poll->failing());
        $this->assertSame(2, $poll->checkFailures);
        $this->assertFileExists($this->stamps.'/a.poll');
    }

    public function testPreflightFailureIsRecorded(): void
    {
        $env = $this->env();
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'gh' === $argv[0]
            ? new \Pablo\Support\ProbeResult(1, 'not authenticated')
            : new \Pablo\Support\ProbeResult(0, 'ok');
        ob_start();
        $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        ob_end_clean();

        $last = (new DispatchHealth())->lastDispatch();
        $this->assertNotNull($last);
        $this->assertSame(DispatchHealth::OUTCOME_PREFLIGHT_FAILED, $last->outcome);
        $this->assertStringContainsString('gh: not authenticated', (string) $last->detail);
    }

    /** @return list<string> the CLIs probed so far, by argv[0] */
    private function probedClis(): array
    {
        return array_map(static fn (array $argv): string => $argv[0], $this->runner->probeCalls);
    }

    public function testIdleTickSpawnsNoSubprocessButCountsAsAHealthyRun(): void
    {
        $env = $this->env();
        $now = 1_800_000_000.0;
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->ran = [];
        $this->runner->probeCalls = [];
        $this->runner->calls = [];

        // Five minutes later nothing is due (poll every 10, sync every 30).
        ob_start();
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 300);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $rc);
        $this->assertSame([], $this->ran);
        $this->assertSame([], $this->runner->probeCalls, 'an idle tick probes no CLI');
        $this->assertSame([], $this->runner->calls, 'an idle tick runs no command');
        $this->assertSame([], $this->git->primaryUpdates);
        $this->assertSame('', $out);

        // The scheduler still looks alive to the doctor and the dashboard.
        $last = (new DispatchHealth())->lastDispatch();
        $this->assertNotNull($last);
        $this->assertSame(DispatchHealth::OUTCOME_OK, $last->outcome);
        $this->assertSame(DispatchHealth::iso($now + 300), $last->startedAt);
        $this->assertNotNull($last->finishedAt);
    }

    public function testDueTickProbesEachCliOnce(): void
    {
        $env = $this->env();
        ob_start();
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners']);
        ob_end_clean();

        $this->assertSame(0, $rc);
        $this->assertCount(4, $this->ran);
        $probed = $this->probedClis();
        $this->assertSame(['gh'], array_values(array_filter($probed, static fn (string $cli): bool => 'gh' === $cli)), 'one preflight for the whole run, not one per job');
        $this->assertSame(array_values(array_unique($probed)), $probed);
    }

    public function testPreflightOnlyProbesTheTrackersOfProjectsWithAJobDue(): void
    {
        $now = microtime(true); // stampAge() ages stamps from the real clock
        $projects = ['jira' => $this->cfg('jira', 'jira'), 'b' => $this->cfg('b')];
        $runners = ['poll' => function (ProjectConfig $c, Store $s): void { $this->ran[] = ['poll', $c->name]; }];
        $store = new Store($this->tmp.'/state');
        $this->runDispatch($projects, $store, $runners, $now);
        $this->assertContains('acli', $this->probedClis());

        // Only b is due again: the Jira CLI is not needed by this run.
        $this->stampAge('b', 'poll', 11 * 60);
        $this->ran = [];
        $this->runner->probeCalls = [];
        $this->runDispatch($projects, $store, $runners, $now + 1);
        $this->assertSame([['poll', 'b']], $this->ran);
        $this->assertContains('gh', $this->probedClis());
        $this->assertNotContains('acli', $this->probedClis());
    }

    /**
     * A tick whose only due job is backing off runs nothing, so it needs no
     * tool: it is idle (no probe) and, as before, a healthy run — the failing
     * job is reported by its own health record, not by the run.
     */
    public function testBackedOffJobAloneIsAnIdleTick(): void
    {
        $env = $this->env();
        $env['runners'] = ['sync' => $this->failingSync()];
        $now = 1_800_000_000.0;
        ob_start();
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 300);
        ob_end_clean();
        $this->assertSame(2, $this->syncFailures());

        // +600s: both syncs are due (they never succeeded) but back off until +900s.
        $this->ran = [];
        $this->runner->probeCalls = [];
        ob_start();
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 600);
        $out = (string) ob_get_clean();

        $this->assertSame(0, $rc);
        $this->assertSame([], $this->ran);
        $this->assertSame([], $this->runner->probeCalls);
        $this->assertStringContainsString('[a] sync: backing off', $out);
        $this->assertSame(DispatchHealth::OUTCOME_OK, (new DispatchHealth())->lastDispatch()?->outcome);
        $this->assertSame(2, $this->syncFailures());
    }

    public function testIdleTickStillFailsOnAnImpossibleAgentSetup(): void
    {
        $env = $this->env();
        $now = 1_800_000_000.0;
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->runner->probeCalls = [];

        $this->writeGlobalConfig("agent_runtime: claude-code\nagent_backend: openchamber\n");
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 300);

        $this->assertSame(1, $rc);
        $this->assertSame([], $this->runner->probeCalls, 'the agent settings check is a config read');
        $last = (new DispatchHealth())->lastDispatch();
        $this->assertNotNull($last);
        $this->assertSame(DispatchHealth::OUTCOME_PREFLIGHT_FAILED, $last->outcome);
        $this->assertStringContainsString('OpenChamber only works with OpenCode', (string) $last->detail);
    }

    public function testIdleTickStillReportsInvalidProjectConfigs(): void
    {
        $env = $this->env();
        $now = 1_800_000_000.0;
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->runner->probeCalls = [];
        $err = fopen('php://memory', 'w+');
        \assert(false !== $err);
        $this->dispatch->stderr = $err;

        $rc = $this->dispatch->run($env['projects'], $env['store'], $env['runners'], $now + 300, ['/x/bad.yaml' => 'bad.yaml: invalid YAML']);

        $this->assertSame(1, $rc);
        $this->assertSame([], $this->runner->probeCalls);
        rewind($err);
        $this->assertStringContainsString('skipping invalid project config /x/bad.yaml', (string) stream_get_contents($err));
        $last = (new DispatchHealth())->lastDispatch();
        $this->assertSame(DispatchHealth::OUTCOME_FAILED, $last?->outcome);
        $this->assertSame('invalid project config: bad.yaml', $last->detail);
    }

    public function testPreflightFailureLeavesJobsDueSoTheNextTickChecksAgain(): void
    {
        $env = $this->env();
        $now = 1_800_000_000.0;
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => 'gh' === $argv[0]
            ? new \Pablo\Support\ProbeResult(1, 'not authenticated')
            : new \Pablo\Support\ProbeResult(0, 'ok');
        ob_start();
        $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now);
        $this->runner->probeCalls = [];
        $rc = $this->runDispatch($env['projects'], $env['store'], $env['runners'], $now + 300);
        ob_end_clean();

        // No stamp was written, so the failure stays visible on every tick.
        $this->assertSame(1, $rc);
        $this->assertSame([], $this->ran);
        $this->assertContains('gh', $this->probedClis());
        $this->assertSame(DispatchHealth::OUTCOME_PREFLIGHT_FAILED, (new DispatchHealth())->lastDispatch()?->outcome);
    }
}
