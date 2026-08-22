<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Config\ProjectConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use PHPUnit\Framework\TestCase;

final class DispatchTest extends TestCase
{
    private string $tmp;
    private string $stamps;
    private Dispatch $dispatch;
    private \Pablo\Tests\FakeProcessRunner $runner;

    /** @var list<array{0: string, 1: string}> */
    private array $ran;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-dispatch-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->stamps = $this->tmp.'/stamps';
        putenv('PABLO_STAMPS_DIR='.$this->stamps);
        $this->ran = [];
        $this->runner = new \Pablo\Tests\FakeProcessRunner();
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(0, 'ok');
        $global = new \Pablo\Config\GlobalConfig();
        $doctor = new Doctor($this->runner, new \Pablo\Agents\AgentLauncherFactory($global));
        $time = new \Pablo\Domain\Time();
        $git = new \Pablo\Tests\FakeGit();
        $gh = new \Pablo\Tests\FakeGhPr();
        $repoSlug = new \Pablo\Support\RepoSlug($git);
        $providers = new \Pablo\Tests\StubProviders();
        $sm = new \Pablo\StateMachine\StateMachine($gh, $providers, $repoSlug, $time);
        $sync = new \Pablo\Provider\Git\Sync($git, $gh, $repoSlug, $this->runner, $time);
        $poller = new \Pablo\Poller\Poller($gh, $git, $providers, $repoSlug, $sm, $time);
        $stamps = new \Pablo\Dispatch\Stamps();
        $this->dispatch = new Dispatch($doctor, $sync, $poller, new \Pablo\Agents\AgentLauncherFactory($global), $stamps);
        $fh = fopen('/dev/null', 'w');
        \assert(false !== $fh);
        $this->dispatch->stderr = $fh;
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
    }

    private function cfg(string $name): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repoPath: $this->tmp.'/'.$name,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wt/'.$name,
            provider: 'github',
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    /** @param array<string, callable(ProjectConfig, Store): void>|null $runners */
    /** @param array<string, callable(ProjectConfig, Store): void>|null $runners
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
     * @param array<string, callable(ProjectConfig, Store): void> $runners
     */
    private function runDispatch(array $projects, Store $store, array $runners, ?float $now = null): int
    {
        return $this->dispatch->run($projects, $store, $runners, $now);
    }

    private function stampAge(string $name, string $job, int $secondsAge): void
    {
        $path = $this->stamps.'/'.$name.'.'.$job;
        @mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, (new \Pablo\Dispatch\Stamps())->encodeStamp(microtime(true) - $secondsAge, 0.0));
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

    public function testSameRepoSyncsEachProjectsTasks(): void
    {
        $sharedRepo = $this->tmp.'/shared-repo';
        mkdir($sharedRepo, 0o777, true);

        $a = new ProjectConfig(
            name: 'a', type: 'work', repoPath: $sharedRepo,
            primaryBranch: 'main', worktreesRoot: $this->tmp.'/wt/a',
            provider: 'github', identity: 'octocat', projectKey: 'PR',
            syncStrategy: 'rebase', syncAutoApply: false,
            syncInterval: 30, pollInterval: 10,
            failureSignal: null, botWhitelist: [], ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
        $b = new ProjectConfig(
            name: 'b', type: 'work', repoPath: $sharedRepo,
            primaryBranch: 'main', worktreesRoot: $this->tmp.'/wt/b',
            provider: 'github', identity: 'octocat', projectKey: 'PB',
            syncStrategy: 'rebase', syncAutoApply: false,
            syncInterval: 30, pollInterval: 10,
            failureSignal: null, botWhitelist: [], ciIgnoreChecks: [],
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
}
