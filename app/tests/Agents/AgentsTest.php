<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\Agents;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * The sole Agents test that exercises the REAL implementation (via the Proc
 * seam) — never the FakeAgents stub. Spawning tests are excluded because they
 * rely on subprocess.Popen interception PHP cannot provide safely.
 */
final class AgentsTest extends TestCase
{
    protected FakeProcessRunner $runner;

    /** Registers a canned runner and returns it. */
    private function startRunner(callable $fn): FakeProcessRunner
    {
        $this->runner->onRun = $fn;

        return $this->runner;
    }
    private string $tmp;
    private string $agentsDir;
    private Agents $agents;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-agents-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->agentsDir = $this->tmp.'/agents';
        putenv('PABLO_AGENTS_DIR='.$this->agentsDir);
        $this->runner = new FakeProcessRunner();
        $this->agents = new Agents($this->runner, new \Pablo\Domain\Time(), null, '/x/pablo');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
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

    public function testDetachedSpawnActuallyExecutesInnerCommand(): void
    {
        $marker = $this->tmp.'/ran.txt';
        $method = new \ReflectionMethod(Agents::class, 'detach');
        $command = (string) $method->invoke($this->agents, 'echo DETACHED_RAN > '.$marker);
        $pid = (int) trim((string) shell_exec($command));
        $deadline = microtime(true) + 5;
        $content = '';
        while ('DETACHED_RAN' !== ($content = trim((string) @file_get_contents($marker))) && microtime(true) < $deadline) {
            usleep(50_000);
        }

        $this->assertGreaterThan(0, $pid);
        $this->assertSame('DETACHED_RAN', $content);
    }

    public function testOrcaLaunchBuildsCommand(): void
    {
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk(['handle' => 'term_123']);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45');
        $this->assertSame('term_123', $handle);
        $this->assertCount(2, $calls);
        // First: the cold-worktree adoption poll; then the terminal create.
        $this->assertSame(['orca', 'worktree', 'show'], \array_slice($calls[0], 0, 3));
        $argv = $calls[1];
        $this->assertSame(['orca', 'terminal', 'create'], \array_slice($argv, 0, 3));
        $this->assertContains('path:'.$this->tmp, $argv);
        $this->assertContains('pablo:task-analyst', $argv);
        $key = array_search('--command', $argv, true);
        $this->assertIsInt($key);
        $command = $argv[$key + 1];
        $this->assertStringStartsWith('cd ', $command);
        $this->assertStringContainsString('opencode run', $command);
        $this->assertStringContainsString('--agent task-analyst', $command);
        $this->assertStringNotContainsString('--prompt', $command);
        $this->assertStringContainsString('Analyze issue #45', $command);
        $this->assertStringNotContainsString('--model', $command);
    }

    public function testOrcaLaunchPassesModelToCommand(): void
    {
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk(['handle' => 'term_123']);
        });

        $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45', 'litellm/anthropic/claude-opus');
        $key = array_search('--command', $calls[1], true);
        $this->assertIsInt($key);
        $command = $calls[1][$key + 1];
        $this->assertStringContainsString("--model 'litellm/anthropic/claude-opus'", $command);
    }

    private function claudeAgents(): Agents
    {
        $runtime = new \Pablo\Agents\Runtime\ClaudeCodeRuntime(
            new \Pablo\Agents\Runtime\ClaudeCodeAgents(new \Pablo\Agents\AgentTemplates(new \Pablo\Config\Config(new \Pablo\Config\GlobalConfig()))),
            $this->tmp.'/claude',
        );

        return new Agents($this->runner, new \Pablo\Domain\Time(), null, '/x/pablo', $runtime);
    }

    /** Launch a Claude agent in Orca; returns the terminal command and the done marker. */
    private function launchClaude(Agents $agents): string
    {
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk(['handle' => 'term_7']);
        });
        $this->assertSame('term_7', $agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45'));
        $key = array_search('--command', $calls[1], true);
        $this->assertIsInt($key);

        return $calls[1][$key + 1];
    }

    /** In Orca, Claude Code runs its own interface, so the user sees the conversation. */
    public function testClaudeRunsInteractivelyInOrca(): void
    {
        $command = $this->launchClaude($this->claudeAgents());

        $this->assertStringStartsWith('cd ', $command);
        $this->assertStringNotContainsString('claude -p', $command);
        $this->assertStringContainsString("claude --settings '", $command);
        $this->assertStringContainsString('--agent task-analyst', $command);
        $this->assertStringContainsString('--permission-mode dontAsk', $command, 'same permissions as headless');
        $this->assertMatchesRegularExpression('/--session-id [0-9a-f-]{36}/', $command);
        $this->assertStringContainsString('"Stop"', $command);
        $this->assertStringContainsString($this->agentsDir.'/done/', $command);
    }

    public function testTheWorktreeIsTrustedBeforeAnInteractiveLaunch(): void
    {
        $file = $this->tmp.'/.claude.json';
        file_put_contents($file, '{"projects":{}}');
        putenv('PABLO_CLAUDE_JSON='.$file);
        try {
            $this->launchClaude($this->claudeAgents());
        } finally {
            putenv('PABLO_CLAUDE_JSON=/nonexistent/pablo-tests/.claude.json');
        }

        $this->assertSame(['projects' => [$this->tmp => ['hasTrustDialogAccepted' => true]]], json_decode((string) file_get_contents($file), true));
    }

    public function testAnInteractiveRunEndsWhenItsStopHookFires(): void
    {
        $agents = $this->claudeAgents();
        $this->launchClaude($agents);
        $markers = glob($this->agentsDir.'/done/*.marker') ?: [];
        $this->assertCount(1, $markers);
        $doneMarker = (string) file_get_contents($markers[0]);

        $waits = 0;
        $this->startRunner(function (array $argv) use (&$waits, $doneMarker): string {
            $this->assertSame(['orca', 'terminal', 'wait', '--terminal', 'term_7', '--for', 'exit'], \array_slice($argv, 0, 7));
            if (2 === ++$waits) {
                touch($doneMarker); // the agent answered, the terminal stays open
            }

            return $this->orcaErr('timeout');
        });
        $agents->waitForHandle('term_7', 60);

        $this->assertSame(2, $waits);
        $this->assertFileDoesNotExist($doneMarker);
        $this->assertSame([], glob($this->agentsDir.'/done/*') ?: []);
    }

    public function testAnInteractiveRunEndsWhenTheUserClosesIt(): void
    {
        $agents = $this->claudeAgents();
        $this->launchClaude($agents);

        $waits = 0;
        $this->startRunner(function () use (&$waits): string {
            ++$waits;

            return $this->orcaOk(['satisfied' => true]);
        });
        $agents->waitForHandle('term_7', 60);

        $this->assertSame(1, $waits);
    }

    public function testAOneShotRunWaitsForItsTerminalToExit(): void
    {
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaErr('timeout');
        });
        $this->agents->waitForHandle('term_9', 60);

        $this->assertCount(1, $calls, 'one wait, for the whole timeout');
        $this->assertContains('60000', $calls[0]);
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
        $this->startRunner(fn (array $argv): string => $this->orcaOk($ps));
        $sessions = $this->agents->activeSessions($this->tmp);
        $this->assertSame([['a', 'running'], ['b', 'waiting']], array_map(static fn ($s) => [$s->handle, $s->status], $sessions));
    }

    public function testFinishedAgentDoesNotCountAsActiveSession(): void
    {
        $ps = [
            'worktrees' => [
                [
                    'path' => $this->tmp,
                    'agents' => [
                        ['paneKey' => 'a', 'state' => 'done'],
                        ['paneKey' => 'b', 'state' => 'working'],
                    ],
                ],
            ],
        ];
        $this->startRunner(fn (array $argv): string => $this->orcaOk($ps));
        $sessions = $this->agents->activeSessions($this->tmp);
        // The finished "done" agent is skipped so the worktree can be closed.
        $this->assertSame([['b', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $sessions));
    }

    public function testOnlyFinishedAgentBlocksNothing(): void
    {
        $ps = [
            'worktrees' => [
                [
                    'path' => $this->tmp,
                    'agents' => [
                        ['paneKey' => 'a', 'state' => 'done'],
                        ['paneKey' => 'b', 'state' => 'completed'],
                    ],
                ],
            ],
        ];
        $this->startRunner(fn (array $argv): string => $this->orcaOk($ps));
        $this->assertSame([], $this->agents->activeSessions($this->tmp));
    }

    public function testDisplaySessionsCountsFinishedAgentAsWaiting(): void
    {
        $ps = [
            'worktrees' => [
                [
                    'path' => $this->tmp,
                    'agents' => [
                        ['paneKey' => 'a', 'state' => 'done'],
                        ['paneKey' => 'b', 'state' => 'completed'],
                        ['paneKey' => 'c', 'state' => 'working'],
                    ],
                ],
            ],
        ];
        $this->startRunner(fn (array $argv): string => $this->orcaOk($ps));
        // Display-only path: finished analysts surface as waiting so the
        // "💭 Waiting for feedback" split can surface them.
        $this->assertSame(
            [['a', 'waiting'], ['b', 'waiting'], ['c', 'running']],
            array_map(static fn ($s) => [$s->handle, $s->status], $this->agents->displaySessions($this->tmp)),
        );
        // The operational path keeps excluding them (closure/relaunch).
        $this->assertSame(
            [['c', 'running']],
            array_map(static fn ($s) => [$s->handle, $s->status], $this->agents->activeSessions($this->tmp)),
        );
    }

    public function testBulkDisplaySessionsCountsFinishedAgentAsWaiting(): void
    {
        $wtA = $this->tmp.'/a';
        $wtB = $this->tmp.'/b';
        mkdir($wtA, 0o777, true);
        mkdir($wtB, 0o777, true);
        $ps = [
            'worktrees' => [
                ['path' => $wtA, 'agents' => [['paneKey' => 'a1', 'state' => 'done']]],
                ['path' => $wtB, 'agents' => [['paneKey' => 'b1', 'state' => 'working']]],
            ],
        ];
        $this->startRunner(fn (array $argv): string => $this->orcaOk($ps));
        $result = $this->agents->bulkDisplaySessions([$wtA, $wtB]);
        $this->assertSame([['a1', 'waiting']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtA]));
        $this->assertSame([['b1', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtB]));
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
        $this->startRunner(function (array $argv) use (&$calls, $ps): string {
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
        $this->startRunner(fn (array $argv): string => $this->orcaErr('down'));
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
        $this->startRunner(function (array $argv) use (&$calls): string {
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
        $key = array_search('--issue', $argv, true);
        $this->assertIsInt($key);
        $this->assertSame('null', $argv[$key + 1]);
    }

    public function testSetWorktreeDisplayNameLinksGithubIssue(): void
    {
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->orcaOk([]);
        });
        $this->agents->setWorktreeDisplayName($this->tmp, 'OMS-6407', '273');
        $argv = $calls[0];
        $key = array_search('--issue', $argv, true);
        $this->assertIsInt($key);
        $this->assertSame('273', $argv[$key + 1]);
    }

    public function testSetWorktreeDisplayNameSilentlyIgnoresFailure(): void
    {
        $this->startRunner(fn (array $argv): string => $this->orcaErr('selector_not_found'));
        $this->agents->setWorktreeDisplayName($this->tmp, 'OMS-6407'); // must not throw
        $this->addToAssertionCount(1);
    }
}
