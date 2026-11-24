<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\OpenChamber;

use Pablo\Agents\Ade\OpenChamber\OpenChamberLauncher;
use Pablo\Domain\Time;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * OpenChamber backend tests, exercising the REAL implementation via the Proc
 * seam (like AgentsTest) — never a live openchamber daemon and never a real
 * `opencode run`.
 */
final class OpenChamberLauncherTest extends TestCase
{
    use SandboxedEnv;

    protected FakeProcessRunner $runner;

    /** Registers a canned runner and returns it. */
    private function startRunner(callable $fn): FakeProcessRunner
    {
        $this->runner->onRun = $fn;

        return $this->runner;
    }
    private string $tmp;
    private string $agentsDir;
    private OpenChamberLauncher $agents;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('oc');
        $this->agentsDir = $this->tmp.'/agents';
        $this->setEnv('PABLO_AGENTS_DIR', $this->agentsDir);
        // The agent files are read from ~/.pablo/runtime/opencode/agents.
        $this->setEnv('PABLO_RUNTIME_DIR', $this->tmp.'/runtime');
        $this->agentFilesDir = $this->tmp.'/runtime/opencode/agents';
        mkdir($this->agentFilesDir, 0o777, true);
        $this->runner = new FakeProcessRunner();
        $this->sleeps = [];
        $this->agents = new OpenChamberLauncher(
            $this->runner,
            new Time(),
            \Pablo\Tests\Registries::defaultRuntime(),
            function (int $us): void { $this->sleeps[] = $us; },
        );
    }

    /** @var list<int> */
    private array $sleeps = [];

    private string $agentFilesDir;

    protected function tearDown(): void
    {
        $this->restoreEnv();
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
        $this->startRunner(function (array $argv) use (&$calls): string {
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
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return $this->ocErr(['error' => ['message' => 'no daemon']]);
        });
        mkdir($this->agentsDir, 0o777, true);

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi');
        // Falls back to the shared headless path -> pid handle, and the
        // failure is logged for diagnosis.
        $log = (string) file_get_contents($this->agentsDir.'/logs/openchamber-fallback.log');

        $this->assertStringStartsWith('pid:', $handle);
        $this->assertStringContainsString('session create failed', $log);
        $this->assertStringContainsString('no daemon', $log);
        // Not a "not ready" refusal: no retry.
        $this->assertCount(1, array_filter($calls, static fn (array $argv): bool => \in_array('create', $argv, true)));
        $this->assertSame([], $this->sleeps);
    }

    private function notReady(): string
    {
        return $this->ocErr(['error' => ['message' => 'Session selection is not ready: the model catalog is unavailable']]);
    }

    public function testCreateIsRetriedWhileTheModelCatalogLoads(): void
    {
        $creates = 0;
        $this->startRunner(function (array $argv) use (&$creates): string {
            if (\in_array('create', $argv, true)) {
                return 1 === ++$creates
                    ? $this->notReady()
                    : $this->ocOk(['sessionId' => 'ses_late']);
            }

            return $this->ocOk(['action' => 'send', 'sessionId' => 'ses_late']);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi');

        $this->assertSame('ses_late', $handle);
        $this->assertSame(2, $creates);
        $this->assertCount(1, $this->sleeps);
        $this->assertFileDoesNotExist($this->agentsDir.'/logs/openchamber-fallback.log');
    }

    public function testCreateFallsBackWhenTheModelCatalogNeverLoads(): void
    {
        $creates = 0;
        $this->startRunner(function (array $argv) use (&$creates): string {
            if (\in_array('create', $argv, true)) {
                ++$creates;
            }

            return $this->notReady();
        });
        mkdir($this->agentsDir, 0o777, true);

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi');
        $log = (string) file_get_contents($this->agentsDir.'/logs/openchamber-fallback.log');

        $this->assertStringStartsWith('pid:', $handle);
        $this->assertSame(4, $creates);
        $this->assertCount(3, $this->sleeps);
        $this->assertStringContainsString('session create failed after 3 retries', $log);
        $this->assertStringContainsString('model catalog is unavailable', $log);
    }

    public function testLaunchFallsBackToHeadlessWhenSendFails(): void
    {
        // A failed `session send` (e.g. an unknown model id) must not leave the
        // created session tracked: the watcher would report the empty zombie
        // finished within seconds and the display would park it as waiting.
        mkdir($this->agentsDir, 0o777, true);
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc', 'directory' => $this->tmp, 'title' => 'pablo:task-analyst']);
            }

            return $this->ocErr(['error' => ['message' => "Unknown model 'claude-code/claude-opus-5-5' for {$this->tmp}"]]);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi', 'claude-code/claude-opus-5-5');
        $log = (string) file_get_contents($this->agentsDir.'/logs/openchamber-fallback.log');

        $this->assertStringStartsWith('pid:', $handle);
        // create, send, then one delivery check per wait, all unanswered.
        $this->assertCount(5, $calls);
        $this->assertSame(['openchamber', 'session', 'messages'], \array_slice($calls[2], 0, 3));
        $this->assertCount(3, $this->sleeps);
        $this->assertFileDoesNotExist($this->agentsDir.'/openchamber-ses_abc.json');
        $this->assertStringContainsString('session send failed (ses_abc)', $log);
        $this->assertStringContainsString('Unknown model', $log);
    }

    public function testSendTimeoutKeepsSessionWhenThePromptLanded(): void
    {
        // The CLI gives up after 4 s on a slow daemon, which still takes the
        // prompt: a headless fallback then would run the agent twice.
        mkdir($this->agentsDir, 0o777, true);
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc']);
            }
            if (\in_array('send', $argv, true)) {
                return $this->ocErr(['error' => ['message' => 'Request to /api/openchamber/control timed out after 4000ms.']]);
            }

            return $this->ocOk(['sessionStatus' => ['type' => 'idle'], 'messages' => [['role' => 'user', 'text' => 'hi']]]);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi');
        $log = (string) file_get_contents($this->agentsDir.'/logs/openchamber-fallback.log');

        $this->assertSame('ses_abc', $handle);
        $this->assertCount(3, $calls);
        $this->assertFileExists($this->agentsDir.'/openchamber-ses_abc.json');
        $this->assertStringContainsString('prompt was delivered (ses_abc), no fallback', $log);
    }

    public function testSendTimeoutKeepsSessionOnceTheDaemonCatchesUp(): void
    {
        mkdir($this->agentsDir, 0o777, true);
        $checks = 0;
        $this->startRunner(function (array $argv) use (&$checks): string {
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc']);
            }
            if (\in_array('send', $argv, true)) {
                return $this->ocErr(['error' => ['message' => 'timed out']]);
            }
            ++$checks;

            // First check: the daemon has not answered yet; second: busy.
            return 1 === $checks
                ? $this->ocErr(['error' => ['message' => 'timed out']])
                : $this->ocOk(['sessionStatus' => ['type' => 'busy'], 'messages' => []]);
        });

        $this->assertSame('ses_abc', $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi'));
        $this->assertSame(2, $checks);
    }

    public function testSendFailureFallsBackWhenTheSessionStaysEmpty(): void
    {
        mkdir($this->agentsDir, 0o777, true);
        $this->startRunner(function (array $argv): string {
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc']);
            }
            if (\in_array('send', $argv, true)) {
                return $this->ocErr(['error' => ['message' => 'timed out']]);
            }

            return $this->ocOk(['sessionStatus' => ['type' => 'idle'], 'messages' => []]);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'hi');

        $this->assertStringStartsWith('pid:', $handle);
        $this->assertFileDoesNotExist($this->agentsDir.'/openchamber-ses_abc.json');
    }

    public function testLaunchPinsModelFromAgentFrontmatter(): void
    {
        file_put_contents($this->agentFilesDir.'/task-analyst.md', <<<'MD'
---
mode: primary
model: litellm/glm-5.3-flash
temperature: 0.2
---

Body.
MD);
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc', 'directory' => $this->tmp, 'title' => 'pablo:task-analyst']);
            }

            return $this->ocOk(['action' => 'send', 'sessionId' => 'ses_abc']);
        });

        $handle = $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45');

        $this->assertSame('ses_abc', $handle);
        $this->assertSame('litellm/glm-5.3-flash', $this->argvValue($calls[0], '--model'));
        $this->assertSame('litellm/glm-5.3-flash', $this->argvValue($calls[1], '--model'));
    }

    public function testLaunchOmitsModelWhenFrontmatterHasNone(): void
    {
        file_put_contents($this->agentFilesDir.'/task-analyst.md', <<<'MD'
---
mode: primary
---

Body.
MD);
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc', 'directory' => $this->tmp, 'title' => 'pablo:task-analyst']);
            }

            return $this->ocOk(['action' => 'send', 'sessionId' => 'ses_abc']);
        });

        $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45');

        $this->assertNull($this->argvValue($calls[0], '--model'));
        $this->assertNull($this->argvValue($calls[1], '--model'));
    }

    public function testResolvedModelOverridesFrontmatter(): void
    {
        file_put_contents($this->agentFilesDir.'/task-analyst.md', <<<'MD'
---
mode: primary
model: litellm/glm-5.3-flash
---

Body.
MD);
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
            $calls[] = $argv;
            if (\in_array('create', $argv, true)) {
                return $this->ocOk(['sessionId' => 'ses_abc', 'directory' => $this->tmp, 'title' => 'pablo:task-analyst']);
            }

            return $this->ocOk(['action' => 'send', 'sessionId' => 'ses_abc']);
        });

        $this->agents->doLaunchAgent($this->tmp, 'task-analyst', 'Analyze issue #45', 'litellm/anthropic/claude-opus');

        $this->assertSame('litellm/anthropic/claude-opus', $this->argvValue($calls[0], '--model'));
        $this->assertSame('litellm/anthropic/claude-opus', $this->argvValue($calls[1], '--model'));
    }

    /** @param array<mixed> $argv
     *
     * @return string|null value following the flag, or null when absent
     */
    private function argvValue(array $argv, string $flag): ?string
    {
        $key = array_search($flag, $argv, true);
        if (false === $key) {
            return null;
        }

        return (string) $argv[(int) $key + 1];
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
        $this->startRunner(fn (array $argv): string => $this->ocOk([
            'sessions' => [
                ['id' => 'ses_a', 'status' => ['type' => 'busy']],
            ],
        ]));

        $sessions = $this->agents->activeSessions($this->tmp);
        $this->assertSame([['ses_a', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $sessions));
    }

    public function testActiveSessionsExcludesIdle(): void
    {
        $this->startRunner(fn (array $argv): string => $this->ocOk([
            'sessions' => [
                ['id' => 'ses_a', 'status' => ['type' => 'idle']],
            ],
        ]));

        // Idle = finished; must not block closure or relaunch.
        $this->assertSame([], $this->agents->activeSessions($this->tmp));
    }

    public function testDisplaySessionsCountsIdleAsWaiting(): void
    {
        $this->startRunner(fn (array $argv): string => $this->ocOk([
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

    public function testBulkDisplaySessionsOneListCallPerWorktree(): void
    {
        $wtA = $this->tmp.'/a';
        $wtB = $this->tmp.'/b';
        $this->startRunner(function (array $argv): string {
            $key = array_search('--dir', $argv, true);
            $this->assertIsInt($key);
            $dir = $argv[$key + 1];

            return $this->ocOk([
                'sessions' => [[
                    'id' => str_contains((string) $dir, '/a') ? 'ses_a' : 'ses_b',
                    'status' => ['type' => 'busy'],
                ]],
            ]);
        });

        $result = $this->agents->bulkDisplaySessions([$wtA, $wtB]);
        $this->assertSame([['ses_a', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtA]));
        $this->assertSame([['ses_b', 'running']], array_map(static fn ($s) => [$s->handle, $s->status], $result[$wtB]));
    }

    public function testSetWorktreeDisplayNameIsNoOp(): void
    {
        // Must not call any CLI and must not throw.
        $this->startRunner(fn (array $argv): string => $this->fail('should not call openchamber'));
        $this->agents->setWorktreeDisplayName($this->tmp, 'WK-45', '45');
        $this->addToAssertionCount(1);
    }

    public function testWaitForHandleWaitsThenCleansUpSessionFile(): void
    {
        $this->writeSession('ses_abc', $this->tmp);
        $calls = [];
        $this->startRunner(function (array $argv) use (&$calls): string {
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
        $this->startRunner(fn (array $argv): string => $this->fail('should not call openchamber'));
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
