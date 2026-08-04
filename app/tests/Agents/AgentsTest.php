<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\Agents;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

/**
 * The sole Agents test that exercises the REAL implementation (via the Proc
 * seam) — never the FakeAgents stub. Spawning tests are excluded because they
 * rely on subprocess.Popen interception PHP cannot provide safely.
 */
final class AgentsTest extends TestCase
{
    private string $tmp;
    private string $agentsDir;
    private Agents $agents;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-agents-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->agentsDir = $this->tmp.'/agents';
        putenv('PABLO_AGENTS_DIR='.$this->agentsDir);
        $this->agents = new Agents('/x/pablo');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        Proc::setRunner(null);
    }

    /** @param array<string, mixed> $payload */
    private function orcaOk(array $payload): string
    {
        return json_encode(['id' => 'x', 'ok' => true, 'result' => $payload], \JSON_THROW_ON_ERROR);
    }

    private function orcaErr(string $code): string
    {
        return json_encode(['id' => 'x', 'ok' => false, 'error' => ['code' => $code, 'message' => $code]], \JSON_THROW_ON_ERROR);
    }

    public function testOrcaLaunchBuildsCommand(): void
    {
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk(['handle' => 'term_123']);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45');
        $this->assertSame('term_123', $handle);
        $argv = $calls[0];
        $this->assertSame(['orca', 'terminal', 'create'], \array_slice($argv, 0, 3));
        $this->assertContains('path:'.$this->tmp, $argv);
        $this->assertContains('pablo:task-analyst', $argv);
        $command = $argv[array_search('--command', $argv, true) + 1];
        $this->assertStringStartsWith('opencode ', $command);
        $this->assertStringContainsString('--agent task-analyst', $command);
        $this->assertStringContainsString('--prompt', $command);
        $this->assertStringContainsString('Analyze issue #45', $command);
    }

    public function testOrcaSessionsMapAgentStates(): void
    {
        $ps = [
            'worktrees' => [
                [
                    'path' => $this->tmp,
                    'agents' => [
                        ['paneKey' => 'a', 'state' => 'working'],
                        ['paneKey' => 'b', 'state' => 'awaiting-input'],
                    ],
                ],
                ['path' => '/elsewhere', 'agents' => [['paneKey' => 'c', 'state' => 'working']]],
            ],
        ];
        Proc::setRunner(fn (array $argv): string => $this->orcaOk($ps));
        $sessions = $this->agents->activeSessions($this->tmp);
        $this->assertSame([['a', 'running'], ['b', 'waiting']], array_map(static fn ($s) => [$s->handle, $s->status], $sessions));
    }

    public function testBulkActiveSessionsOneOrcaCallForManyWorktrees(): void
    {
        $wtA = $this->tmp.'/a';
        $wtB = $this->tmp.'/b';
        mkdir($wtA, 0o777, true);
        mkdir($wtB, 0o777, true);
        $ps = [
            'worktrees' => [
                ['path' => $wtA, 'agents' => [['paneKey' => 'a1', 'state' => 'working']]],
                ['path' => $wtB, 'agents' => [['paneKey' => 'b1', 'state' => 'awaiting-input']]],
                ['path' => '/elsewhere', 'agents' => [['paneKey' => 'c1', 'state' => 'working']]],
            ],
        ];
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls, $ps): string {
            $calls[] = $argv;

            return $this->orcaOk($ps);
        });
        $result = $this->agents->bulkActiveSessions([$wtA, $wtB]);
        $this->assertCount(1, $calls);
        $this->assertSame([['a1', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtA]));
        $this->assertSame([['b1', 'waiting']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtB]));
    }

    public function testHeadlessSessionsFromPidfiles(): void
    {
        Proc::setRunner(fn (array $argv): string => $this->orcaErr('down'));
        mkdir($this->agentsDir, 0o777, true);
        $alive = getmypid();
        file_put_contents($this->agentsDir.'/'.$alive.'.json', json_encode(['pid' => $alive, 'worktree' => $this->tmp, 'agent' => 'task-analyst']));
        file_put_contents($this->agentsDir.'/999999.json', json_encode(['pid' => 999999, 'worktree' => $this->tmp, 'agent' => 'task-analyst']));

        $sessions = $this->agents->activeSessions($this->tmp);
        $this->assertSame([[\sprintf('pid:%d', $alive), 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $sessions));
        $this->assertFileDoesNotExist($this->agentsDir.'/999999.json');
    }

    public function testWaitForPidHandleReturnsWhenDead(): void
    {
        $this->agents->waitForHandle('pid:999999', 1); // dead pid -> returns immediately
        $this->addToAssertionCount(1);
    }

    public function testSetWorktreeDisplayNameCallsOrca(): void
    {
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk([]);
        });
        $this->agents->setWorktreeDisplayName($this->tmp, 'OMS-6407');
        $argv = $calls[0];
        $this->assertSame(['orca', 'worktree', 'set'], \array_slice($argv, 0, 3));
        $this->assertContains('path:'.$this->tmp, $argv);
        $this->assertContains('OMS-6407', $argv);
        $this->assertContains('--display-name', $argv);
        $this->assertContains('--issue', $argv);
        $this->assertSame('null', $argv[array_search('--issue', $argv, true) + 1]);
    }

    public function testSetWorktreeDisplayNameLinksGithubIssue(): void
    {
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk([]);
        });
        $this->agents->setWorktreeDisplayName($this->tmp, 'OMS-6407', '273');
        $argv = $calls[0];
        $this->assertSame('273', $argv[array_search('--issue', $argv, true) + 1]);
    }

    public function testSetWorktreeDisplayNameSilentlyIgnoresFailure(): void
    {
        Proc::setRunner(fn (array $argv): string => $this->orcaErr('selector_not_found'));
        $this->agents->setWorktreeDisplayName($this->tmp, 'OMS-6407'); // must not throw
        $this->addToAssertionCount(1);
    }
}
