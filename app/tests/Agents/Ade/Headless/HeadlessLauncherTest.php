<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\Headless;

use Pablo\Agents\Ade\Headless\HeadlessLauncher;
use Pablo\Domain\Time;
use Pablo\Support\Files;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * The Headless ADE never calls any ADE CLI: activity comes from the
 * pidfiles alone, and every handle is a `pid:N` one.
 */
final class HeadlessLauncherTest extends TestCase
{
    private string $tmp;
    private string $agentsDir;
    private FakeProcessRunner $runner;
    private HeadlessLauncher $agents;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('headless');
        $this->agentsDir = $this->tmp.'/agents';
        mkdir($this->agentsDir, 0o777, true);
        putenv('PABLO_AGENTS_DIR='.$this->agentsDir);
        $this->runner = new FakeProcessRunner();
        $this->agents = new HeadlessLauncher($this->runner, new Time(), \Pablo\Tests\Registries::defaultRuntime());
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        Files::remove($this->tmp);
    }

    public function testSessionsComeFromLivePidfilesOnly(): void
    {
        $alive = getmypid();
        file_put_contents($this->agentsDir.'/'.$alive.'.json', json_encode(['pid' => $alive, 'worktree' => $this->tmp, 'agent' => 'task-analyst']));
        file_put_contents($this->agentsDir.'/999999.json', json_encode(['pid' => 999999, 'worktree' => $this->tmp, 'agent' => 'ci-analyst']));
        // Another ADE's session record (no pid) is left alone.
        file_put_contents($this->agentsDir.'/openchamber-ses_x.json', json_encode(['session' => 'ses_x', 'worktree' => $this->tmp]));

        $expected = [[\sprintf('pid:%d', $alive), 'running']];
        $map = static fn (array $sessions): array => array_map(static fn ($s) => [$s->handle, $s->status], $sessions);
        $this->assertSame($expected, $map($this->agents->activeSessions($this->tmp)));
        $this->assertSame($expected, $map($this->agents->displaySessions($this->tmp)));
        $this->assertSame($expected, $map($this->agents->bulkDisplaySessions([$this->tmp, '/other'])[$this->tmp]));
        $this->assertSame([], $this->agents->bulkDisplaySessions([$this->tmp, '/other'])['/other']);
        $this->assertTrue($this->agents->hasAnyAgent($this->tmp));
        $this->assertFalse($this->agents->hasAnyAgent('/other'));
        $this->assertFileDoesNotExist($this->agentsDir.'/999999.json');
        $this->assertFileExists($this->agentsDir.'/openchamber-ses_x.json');
        $this->assertSame([], $this->runner->calls, 'no ADE CLI is ever called');
    }

    public function testStartupScriptAndAgentRunHeadless(): void
    {
        $script = $this->tmp.'/startup.sh';
        file_put_contents($script, "true\n");

        $handle = $this->agents->doRunStartupScript($this->tmp, $script);

        $this->assertMatchesRegularExpression('/^pid:\d+$/', $handle);
        $record = json_decode((string) file_get_contents($this->agentsDir.'/'.substr($handle, 4).'.json'), true);
        $this->assertIsArray($record);
        $this->assertSame('startup-script', $record['agent']);
        $this->assertSame($this->tmp, $record['worktree']);
        // Recorded so system:prune never removes a live run's log.
        $this->assertIsString($record['log']);
        $this->assertStringStartsWith($this->agentsDir.'/logs/', $record['log']);
        $this->assertStringEndsWith('-startup-script.log', $record['log']);
        $this->assertSame([], $this->runner->calls);
    }

    /**
     * A real detached run: the runtime's command runs from the worktree, its
     * output lands in the run's log, and the recorded pid is the command's.
     * A blanket `exec` in front of `cd …` once killed every headless run
     * before it started.
     */
    public function testAgentCommandActuallyRunsInTheWorktree(): void
    {
        $runtime = new class extends DelegatingRuntime {
            public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string
            {
                return 'sh -c '.escapeshellarg('echo "ran in $PWD as $$"; echo "detached in '.$detachedIn.'"');
            }
        };
        $worktree = $this->tmp.'/wt';
        mkdir($worktree);
        $agents = new HeadlessLauncher($this->runner, new Time(), $runtime);

        $handle = $agents->launchHeadless($worktree, 'task-analyst', 'go');
        $pid = (int) substr($handle, 4);
        $agents->waitForHandle($handle, 10);

        $logs = Files::list($this->agentsDir.'/logs', '*-task-analyst.log');
        $this->assertCount(1, $logs);
        $log = (string) file_get_contents($logs[0]);
        $this->assertStringContainsString('ran in '.realpath($worktree).' as '.$pid."\n", $log);
        $this->assertStringContainsString('detached in '.$worktree."\n", $log);
    }

    /**
     * A real detached startup script runs from the worktree, never from the
     * caller's cwd (the user's shell, the scheduler's).
     */
    public function testStartupScriptActuallyRunsInTheWorktree(): void
    {
        $worktree = $this->tmp.'/wt dir';
        mkdir($worktree);
        $script = $this->tmp.'/startup.sh';
        file_put_contents($script, "echo \"script ran in \$PWD\"\n");

        $handle = $this->agents->doRunStartupScript($worktree, $script);
        $this->agents->waitForHandle($handle, 10);

        $logs = Files::list($this->agentsDir.'/logs', '*-startup-script.log');
        $this->assertCount(1, $logs);
        $this->assertSame('script ran in '.realpath($worktree)."\n", file_get_contents($logs[0]));
    }

    public function testDisplayNameIsANoOpAndWaitReturnsForADeadPid(): void
    {
        $this->agents->setWorktreeDisplayName($this->tmp, 'PROJ-1', null);
        $this->agents->waitForHandle('pid:999999', 1);
        $this->assertSame([], $this->runner->calls);
    }
}
