<?php

declare(strict_types=1);

namespace Pablo\Tests\Dispatch;

use Pablo\Config\ProjectConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Doctor\CheckResult;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use PHPUnit\Framework\TestCase;

final class DispatchTest extends TestCase
{
    private string $tmp;
    private string $stamps;

    /** @var list<array{0: string, 1: string}> */
    private array $ran;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-dispatch-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->stamps = $this->tmp.'/stamps';
        putenv('PABLO_STAMPS_DIR='.$this->stamps);
        $this->ran = [];
        Dispatch::setPreflightErrors(static fn () => []);
        $fh = fopen('/dev/null', 'w');
        \assert(false !== $fh);
        Dispatch::$stderr = $fh;
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        Dispatch::setPreflightErrors(null);
        Doctor::setCheckAll(null);
        Dispatch::$stderr = null;
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
        return Dispatch::run($projects, $store, $runners, $now);
    }

    private function stampAge(string $name, string $job, int $secondsAge): void
    {
        $path = $this->stamps.'/'.$name.'.'.$job;
        @mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, Dispatch::encodeStamp(microtime(true) - $secondsAge, 0.0));
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

        $stamp = Dispatch::readStamp('a', 'poll');
        $this->assertNotNull($stamp);
        $this->assertGreaterThanOrEqual(0.0, $stamp['ran_at']);
        $this->assertGreaterThanOrEqual(0.0, $stamp['duration_s']);
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
        $results = [
            new CheckResult('gh', true, true, 'ok', ''),
            new CheckResult('orca', true, false, 'timed out after 30s', 'start Orca'),
        ];
        Dispatch::setPreflightErrors(null);
        Doctor::setCheckAll(static fn () => $results);
        ob_start();
        $errors = Dispatch::preflightErrors([]);
        $out = (string) ob_get_clean();
        $this->assertSame([], $errors);
        $this->assertStringContainsString('warning: orca not usable', $out);
    }

    public function testPreflightProviderCliFailuresAreSoft(): void
    {
        $results = [
            new CheckResult('gh', true, true, 'ok', ''),
            new CheckResult('acli', true, false, 'unauthorized', 'run: acli auth login'),
            new CheckResult('acli-confluence', true, false, 'not authenticated', 'run: acli confluence auth login'),
            new CheckResult('linear', true, false, 'not logged in', 'run: linear auth login'),
        ];
        Dispatch::setPreflightErrors(null);
        Doctor::setCheckAll(static fn () => $results);
        ob_start();
        $errors = Dispatch::preflightErrors([]);
        $out = (string) ob_get_clean();
        $this->assertSame([], $errors);
        foreach (['acli', 'acli-confluence', 'linear'] as $cli) {
            $this->assertStringContainsString("warning: {$cli} not usable", $out);
        }
        $this->assertStringNotContainsString('gh', $out);
    }

    public function testPreflightGhFailureIsHard(): void
    {
        $results = [new CheckResult('gh', true, false, 'not logged in', 'run: gh auth login')];
        Dispatch::setPreflightErrors(null);
        Doctor::setCheckAll(static fn () => $results);
        $errors = Dispatch::preflightErrors([]);
        $this->assertNotSame([], $errors);
        $this->assertStringContainsString('gh', $errors[0]);
    }

    public function testPreflightFailureAborts(): void
    {
        $env = $this->env();
        Dispatch::setPreflightErrors(static fn () => ['gh: not authenticated']);
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
        );
        $b = new ProjectConfig(
            name: 'b', type: 'work', repoPath: $sharedRepo,
            primaryBranch: 'main', worktreesRoot: $this->tmp.'/wt/b',
            provider: 'github', identity: 'octocat', projectKey: 'PB',
            syncStrategy: 'rebase', syncAutoApply: false,
            syncInterval: 30, pollInterval: 10,
            failureSignal: null, botWhitelist: [], ciIgnoreChecks: [],
        );

        $store = new Store($this->tmp.'/state');
        $runners = [
            'sync' => function (ProjectConfig $c, Store $_): void { $this->ran[] = ['sync', $c->name]; },
            'poll' => function (ProjectConfig $c, Store $_): void { $this->ran[] = ['poll', $c->name]; },
        ];

        ob_start();
        $rc = Dispatch::run(['a' => $a, 'b' => $b], $store, $runners);
        ob_end_clean();
        $this->assertSame(0, $rc);

        // Projects sharing a repo each sync their own tasks; neither is skipped.
        $pairs = array_map(static fn ($p) => $p[1].':'.$p[0], $this->ran);
        sort($pairs);
        $this->assertSame(['a:poll', 'a:sync', 'b:poll', 'b:sync'], $pairs);
    }
}
