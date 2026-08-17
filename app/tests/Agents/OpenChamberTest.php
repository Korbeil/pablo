<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\OpenChamber;
use Pablo\Support\Proc;
use PHPUnit\Framework\TestCase;

/**
 * OpenChamber backend tests, exercising the REAL implementation via the Proc
 * seam (like AgentsTest) — never a live openchamber daemon and never a real
 * `opencode run`.
 */
final class OpenChamberTest extends TestCase
{
    private string $tmp;
    private string $agentsDir;
    private OpenChamber $agents;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-oc-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $this->agentsDir = $this->tmp.'/agents';
        putenv('PABLO_AGENTS_DIR='.$this->agentsDir);
        $this->agents = new OpenChamber('/x/pablo');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        Proc::setRunner(null);
    }

    /** @param array<string, mixed> $payload */
    private function ocOk(array $payload): string
    {
        return json_encode(['status' => 'ok', ...$payload], \JSON_THROW_ON_ERROR);
    }

    /** @param array<string, mixed> $payload */
    private function ocErr(array $payload): string
    {
        return json_encode(['status' => 'error', ...$payload], \JSON_THROW_ON_ERROR);
    }

    public function testLaunchCreatesThenSendsPrompt(): void
    {
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc', 'directory' => $this->tmp, 'title' => 'pablo:task-analyst']);
            }

            return $this->ocOk(['action' => 'send', 'sessionId' => 'ses_abc']);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45');

        $this->assertSame('ses_abc', $handle);
        $this->assertCount(2, $calls);
        $create = $calls[0];
        $this->assertSame(['openchamber', 'session', 'create'], \array_slice($create, 0, 3));
        $this->assertContains('--dir', $create);
        $this->assertContains($this->tmp, $create);
        $this->assertContains('pablo:task-analyst', $create);
        $send = $calls[1];
        $this->assertSame(['openchamber', 'session', 'send'], \array_slice($send, 0, 3));
        $this->assertContains('--prompt', $send);
        $this->assertContains('Analyze issue #45', $send);
        $this->assertContains('--agent', $send);
        $this->assertContains('task-analyst', $send);
        // A session-id pidfile is written so waitForHandle can find the worktree.
        $this->assertFileExists($this->agentsDir.'/openchamber-ses_abc.json');
    }

    public function testLaunchFallsBackToHeadlessWhenCreateFails(): void
    {
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->ocErr(['error' => ['message' => 'no daemon']]);
        });
        mkdir($this->agentsDir, 0o777, true);

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi');
        // Falls back to the shared headless path -> pid handle.
        $this->assertStringStartsWith('pid:', $handle);
    }

    private function writeSession(string $sessionId, string $worktree): void
    {
        if (!is_dir($this->agentsDir)) {
            mkdir($this->agentsDir, 0o777, true);
        }
        file_put_contents($this->agentsDir.'/openchamber-'.$sessionId.'.json', json_encode([
            'session' => $sessionId,
            'worktree' => $worktree,
            'agent' => 'task-analyst',
        ]));
    }

    public function testActiveSessionsMapsBusyToRunning(): void
    {
        $this->writeSession('ses_a', $this->tmp);
        Proc::setRunner(fn (array $argv): string => $this->ocOk([
            'sessions' => [
                ['id' => 'ses_a', 'status' => ['type' => 'busy']],
            ],
        ]));

        $sessions = $this->agents->activeSessions($this->tmp);
        $this->assertSame([['ses_a', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $sessions));
    }

    public function testActiveSessionsExcludesIdle(): void
    {
        $this->writeSession('ses_a', $this->tmp);
        Proc::setRunner(fn (array $argv): string => $this->ocOk([
            'sessions' => [
                ['id' => 'ses_a', 'status' => ['type' => 'idle']],
            ],
        ]));

        // Idle = finished; must not block closure or relaunch.
        $this->assertSame([], $this->agents->activeSessions($this->tmp));
    }

    public function testDisplaySessionsCountsIdleAsWaiting(): void
    {
        $this->writeSession('ses_a', $this->tmp);
        Proc::setRunner(fn (array $argv): string => $this->ocOk([
            'sessions' => [
                ['id' => 'ses_a', 'status' => ['type' => 'idle']],
                ['id' => 'ses_b', 'status' => ['type' => 'busy']],
            ],
        ]));

        $sessions = $this->agents->displaySessions($this->tmp);
        $this->assertSame(
            [['ses_a', 'waiting'], ['ses_b', 'running']],
            array_map(static fn ($s) => [$s->handle, $s->status], $sessions),
        );
    }

    public function testBulkActiveSessionsOneListCallPerWorktree(): void
    {
        $wtA = $this->tmp.'/a';
        $wtB = $this->tmp.'/b';
        $this->writeSession('ses_a', $wtA);
        $this->writeSession('ses_b', $wtB);
        Proc::setRunner(function (array $argv): string {
            $dir = $argv[array_search('--dir', $argv, true) + 1];

            return $this->ocOk([
                'sessions' => [[
                    'id' => str_contains((string) $dir, '/a') ? 'ses_a' : 'ses_b',
                    'status' => ['type' => 'busy'],
                ]],
            ]);
        });

        $result = $this->agents->bulkActiveSessions([$wtA, $wtB]);
        $this->assertSame([['ses_a', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtA]));
        $this->assertSame([['ses_b', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtB]));
    }

    public function testSetWorktreeDisplayNameIsNoOp(): void
    {
        // Must not call any CLI and must not throw.
        Proc::setRunner(fn (array $argv): string => $this->fail('should not call openchamber'));
        $this->agents->setWorktreeDisplayName($this->tmp, 'WK-45', '45');
        $this->addToAssertionCount(1);
    }

    public function testWaitForHandleWaitsThenCleansUpSessionFile(): void
    {
        $this->writeSession('ses_abc', $this->tmp);
        $calls = [];
        Proc::setRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->ocOk(['sessionId' => 'ses_abc', 'sessionStatus' => ['type' => 'idle'], 'messages' => []]);
        });

        $this->agents->waitForHandle('ses_abc', 1);
        $this->assertCount(1, $calls);
        $argv = $calls[0];
        $this->assertSame(['openchamber', 'session', 'messages'], \array_slice($argv, 0, 3));
        $this->assertContains('--wait', $argv);
        $this->assertFileDoesNotExist($this->agentsDir.'/openchamber-ses_abc.json');
    }

    public function testWaitForHandleUnknownSessionReturnsImmediately(): void
    {
        Proc::setRunner(fn (array $argv): string => $this->fail('should not call openchamber'));
        $this->agents->waitForHandle('ses_unknown', 1);
        $this->addToAssertionCount(1);
    }

    public function testRunStartupScriptUsesHeadlessPidPath(): void
    {
        mkdir($this->agentsDir, 0o777, true);
        $handle = $this->agents->doRunStartupScript($this->tmp, '/x/script.sh');
        $this->assertStringStartsWith('pid:', $handle);
    }
}
