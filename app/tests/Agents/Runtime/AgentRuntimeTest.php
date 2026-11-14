<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\Ade\Orca\OrcaLauncher;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Agents\Runtime\SessionRef;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

/**
 * Runtime selection and the exact commands each runtime launches. Nothing
 * here ever executes `opencode` or `claude`.
 */
final class AgentRuntimeTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('runtime');
        putenv('PABLO_AGENTS_DIR='.$this->tmp.'/agents');
        putenv('PABLO_AGENT_RUNTIME');
        putenv('PABLO_AGENT_BACKEND');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        putenv('PABLO_AGENT_RUNTIME');
        putenv('PABLO_AGENT_BACKEND');
        putenv('PABLO_CLAUDE_JSON');
        putenv(ClaudeCodeRuntime::REMOTE_CONTROL_ENV);
        $this->unsetGlobalConfig();
        self::removeDir($this->tmp);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::removeDir($dir.'/'.$entry);
            }
        }
        @rmdir($dir);
    }

    private function claude(): ClaudeCodeRuntime
    {
        return new ClaudeCodeRuntime(new ClaudeCodeAgents(new AgentTemplates(new Config(new GlobalConfig()))), '/home/u/.pablo/runtime/claude');
    }

    public function testOpenCodeCommandIsUnchanged(): void
    {
        $runtime = new OpenCodeRuntime(\Pablo\Tests\Registries::templates());
        $this->assertSame("opencode run --model 'a/b' --agent task-analyst 'Analyze #1'", $runtime->launchCommand('task-analyst', 'Analyze #1', 'a/b'));
        $this->assertSame("opencode run --agent task-analyst 'x'", $runtime->launchCommand('task-analyst', 'x', null));
        $this->assertSame('opencode -s ses_1', $runtime->sessions()->resumeCommand('ses_1'));
        $this->assertNull($runtime->modelError('opencode-go/glm-5.3-flash'));
    }

    public function testOpenCodeRunsUnderAPabloAssignedSession(): void
    {
        $runtime = new OpenCodeRuntime(\Pablo\Tests\Registries::templates());
        $sid = $runtime->newSessionId();
        $this->assertMatchesRegularExpression('/^ses_pablo[0-9a-f]{20}$/', $sid);
        $this->assertNotSame($sid, $runtime->newSessionId(), 'every launch gets its own session');
        $this->assertSame("opencode run -s '{$sid}' --model 'a/b' --agent task-analyst 'go'", $runtime->launchCommand('task-analyst', 'go', 'a/b', $sid));
        $this->assertTrue($runtime->sessions()->owns($sid));
        $this->assertSame('opencode -s '.$sid, $runtime->sessions()->resumeCommand($runtime->sessions()->handle($sid)));
        // Handles recorded before PABLO assigned ids carry OpenCode's own.
        $this->assertSame('opencode -s ses_3a1b2c', SessionRef::command('ses_3a1b2c'));
        $this->assertNull($runtime->modelError('opencode-go/glm-5.3-flash'));
    }

    public function testClaudeCommandCarriesAgentsSessionAndGuardrails(): void
    {
        $runtime = $this->claude();
        $sid = $runtime->newSessionId();
        $this->assertTrue((new \Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions())->owns($sid));

        $command = $runtime->launchCommand('task-analyst', "Analyze issue #45 — it's urgent", 'sonnet', $sid);
        $this->assertStringStartsWith("claude -p --agents '/home/u/.pablo/runtime/claude/agents.json' --agent task-analyst --model sonnet --session-id {$sid} --strict-mcp-config --permission-mode dontAsk --allowedTools ", $command);
        $this->assertStringContainsString("'Bash(git push*)' 'Bash(rtk git push*)'", $command);
        $this->assertStringContainsString("'Bash(*acli jira workitem edit*)'", $command);
        // The prompt comes last, after `--`, so the variadic tool lists stop before it.
        $this->assertStringEndsWith(' -- '.escapeshellarg("Analyze issue #45 — it's urgent"), $command);

        $resolver = $runtime->launchCommand('rebase-conflict-resolver', 'fix', null);
        $this->assertStringContainsString('--permission-mode acceptEdits', $resolver);
        $this->assertStringNotContainsString('--model', $resolver);
        $this->assertStringNotContainsString('--session-id', $resolver);
    }

    /** A Claude runtime reading a real agents.json under $this->tmp, its trust file in $this->tmp too. */
    private function claudeWithAgentsFile(): ClaudeCodeRuntime
    {
        $this->writeGlobalConfig("{}\n");
        $dir = $this->tmp.'/claude';
        mkdir($dir, 0o777, true);
        $templates = new AgentTemplates(new Config(new GlobalConfig()));
        $agents = new ClaudeCodeAgents($templates);
        file_put_contents($dir.'/agents.json', $agents->renderAgentsJson($templates->templatesDir(), []));
        file_put_contents($this->tmp.'/claude.json', '{"numStartups": 3}');
        putenv('PABLO_CLAUDE_JSON='.$this->tmp.'/claude.json');

        return new ClaudeCodeRuntime($agents, $dir);
    }

    public function testClaudeDetachedRunIsARemoteControlSession(): void
    {
        if (null === (new \Symfony\Component\Process\ExecutableFinder())->find('script')) {
            $this->markTestSkipped('no script(1) on this machine');
        }
        $runtime = $this->claudeWithAgentsFile();
        $worktree = $this->tmp.'/PROJ-12-fix';
        $sid = (string) $runtime->newSessionId();

        $command = $runtime->launchCommand('rebase-conflict-resolver', 'fix it', 'sonnet', $sid, $worktree);

        // A terminal from script(1), the claude command inside it.
        $inner = 'Darwin' === \PHP_OS_FAMILY ? substr($command, \strlen('script -q /dev/null ')) : $command;
        if ('Darwin' !== \PHP_OS_FAMILY) {
            $this->assertMatchesRegularExpression("~^script -qefc '.*' /dev/null$~s", $command);
        }
        $this->assertStringContainsString("claude --remote-control '\\''pablo rebase-conflict-resolver PROJ-12-fix'\\''", $inner);
        $this->assertStringContainsString("--agent rebase-conflict-resolver --model sonnet --session-id {$sid} --strict-mcp-config --settings ", $inner);
        $this->assertStringNotContainsString('claude -p', $inner);
        $this->assertStringNotContainsString('agents.json', $inner, 'interactive claude only takes inline --agents JSON');
        $this->assertStringContainsString('"rebase-conflict-resolver":{"description":', $inner);
        $this->assertStringNotContainsString('"task-analyst"', $inner, 'only the launched agent is inlined');
        $this->assertStringContainsString('"Stop":[{"hooks":[{"type":"command"', $inner);
        $this->assertStringContainsString('"StopFailure":[', $inner);
        // Nobody answers a prompt: even the edit-capable resolver runs in dontAsk.
        $this->assertStringContainsString('--permission-mode dontAsk', $inner);
        $this->assertStringNotContainsString('acceptEdits', $inner);

        $trust = json_decode((string) file_get_contents($this->tmp.'/claude.json'), true);
        $this->assertIsArray($trust);
        $this->assertSame(3, $trust['numStartups'], 'the rest of ~/.claude.json is kept');
        $this->assertTrue($trust['projects'][$worktree]['hasTrustDialogAccepted']);
    }

    public function testClaudeKeepsPrintModeOutsideDetachedRemoteControlRuns(): void
    {
        $runtime = $this->claudeWithAgentsFile();
        $sid = (string) $runtime->newSessionId();

        // In a terminal (Orca, OpenChamber).
        $this->assertStringStartsWith('claude -p ', $runtime->launchCommand('task-analyst', 'go', null, $sid));
        // Switched off, by config then by env.
        $this->writeGlobalConfig("claude_code:\n  remote_control: false\n");
        $this->assertFalse($runtime->remoteControl());
        $this->assertStringStartsWith('claude -p ', $runtime->launchCommand('task-analyst', 'go', null, $sid, $this->tmp.'/wt'));
        $this->writeGlobalConfig("{}\n");
        $this->assertTrue($runtime->remoteControl(), 'on by default');
        putenv(ClaudeCodeRuntime::REMOTE_CONTROL_ENV.'=0');
        try {
            $this->assertStringStartsWith('claude -p ', $runtime->launchCommand('task-analyst', 'go', null, $sid, $this->tmp.'/wt'));
        } finally {
            putenv(ClaudeCodeRuntime::REMOTE_CONTROL_ENV);
        }
        // No agents file to inline: the plain run.
        unlink($this->tmp.'/claude/agents.json');
        $this->assertStringStartsWith('claude -p ', $runtime->launchCommand('task-analyst', 'go', null, $sid, $this->tmp.'/wt'));
        $trust = json_decode((string) file_get_contents($this->tmp.'/claude.json'), true);
        $this->assertIsArray($trust);
        $this->assertArrayNotHasKey('projects', $trust, 'a -p run trusts nothing');
    }

    public function testStopHookEndsTheProcessCarryingTheSessionId(): void
    {
        $sid = '11111111-2222-4333-8444-555555555555';
        // bash stands in for claude: its command line carries the session id,
        // and it runs the hook as a direct child, fed the hook's JSON input.
        $process = new \Symfony\Component\Process\Process([
            'bash', '-c', 'sh -c "$2" <<< "$1"; sleep 20; exit 7',
            'claude --session-id '.$sid, '{"session_id": "'.$sid.'", "hook_event_name": "Stop"}', ClaudeCodeRuntime::STOP_HOOK,
        ]);
        $process->setTimeout(30);
        $start = microtime(true);
        try {
            $process->run();
            $this->fail('the session must be terminated, got exit code '.$process->getExitCode());
        } catch (\Symfony\Component\Process\Exception\ProcessSignaledException $e) {
            $this->assertSame(15, $e->getSignal(), 'terminated by SIGTERM');
        }
        $this->assertLessThan(10, microtime(true) - $start, 'the hook must end the session, not wait for it');
    }

    public function testClaudeModelsAndSessionRefs(): void
    {
        $runtime = $this->claude();
        $this->assertNull($runtime->modelError('sonnet'));
        $this->assertNull($runtime->modelError('claude-opus-5-5'));
        $this->assertStringContainsString('OpenCode provider/model id', (string) $runtime->modelError('opencode-go/glm-5.3-flash'));
        $this->assertSame('claude --resume 0fc45506-985b-4296-be02-a1039fb8be47', $runtime->sessions()->resumeCommand('0fc45506-985b-4296-be02-a1039fb8be47'));
        // The id itself decides, so a log written under the other runtime still reads right.
        $this->assertSame('opencode -s ses_9', SessionRef::command('ses_9'));
    }

    public function testResolverOrder(): void
    {
        $resolver = \Pablo\Tests\Registries::runtimes(new GlobalConfig());
        $this->writeGlobalConfig("{}\n");
        $this->assertSame('opencode', $resolver->resolveName());
        $this->assertInstanceOf(OpenCodeRuntime::class, $resolver->resolve());

        $this->writeGlobalConfig("agent_runtime: claude-code\n");
        $this->assertSame('claude-code', $resolver->resolveName());
        $this->assertInstanceOf(ClaudeCodeRuntime::class, $resolver->resolve());

        putenv('PABLO_AGENT_RUNTIME=opencode');
        $this->assertSame('opencode', $resolver->resolveName());
    }

    public function testUnknownRuntimeIsRefused(): void
    {
        $this->writeGlobalConfig("agent_runtime: codex\n");
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("unknown agent_runtime 'codex'");
        \Pablo\Tests\Registries::runtimes(new GlobalConfig())->resolveName();
    }

    public function testFactoryRefusesOpenChamberWithClaudeCode(): void
    {
        $this->writeGlobalConfig("agent_runtime: claude-code\nagent_backend: openchamber\n");
        $factory = \Pablo\Tests\Registries::launchers(new FakeProcessRunner(), new GlobalConfig());
        try {
            $factory->create();
            $this->fail('OpenChamber + Claude Code must be refused');
        } catch (PabloError $e) {
            $this->assertStringContainsString('OpenChamber only works with OpenCode', $e->getMessage());
        }
        // Orca runs either runtime.
        $launcher = $factory->create('orca');
        $this->assertInstanceOf(OrcaLauncher::class, $launcher);
        $this->assertSame(ClaudeCodeRuntime::NAME, $launcher->runtime()->name());
    }

    public function testOrcaLaunchesTheClaudeCommandInTheWorktree(): void
    {
        $calls = [];
        $runner = new FakeProcessRunner();
        $runner->onRun = static function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return json_encode(['id' => 'x', 'ok' => true, 'result' => ['handle' => 'term_9']], \JSON_THROW_ON_ERROR);
        };
        $agents = new OrcaLauncher($runner, new \Pablo\Domain\Time(), $this->claude());

        $this->assertSame('term_9', $agents->doLaunchAgent($this->tmp, 'ci-analyst', 'Why is CI red?', 'haiku'));
        $key = array_search('--command', $calls[1], true);
        $this->assertIsInt($key);
        $command = $calls[1][$key + 1];
        $this->assertStringStartsWith('cd '.escapeshellarg($this->tmp).'; claude -p ', $command);
        $this->assertStringContainsString('--agent ci-analyst --model haiku --session-id ', $command);
        $this->assertStringEndsWith(" -- 'Why is CI red?'", $command);
    }

    public function testHeadlessLaunchRecordsTheSessionId(): void
    {
        $runtime = new class implements AgentRuntimeInterface {
            public ?string $sessionSeen = null;

            public function name(): string
            {
                return 'claude-code';
            }

            public function binary(): string
            {
                return 'true';
            }

            public function defaultModel(): string
            {
                return 'sonnet';
            }

            public function newSessionId(): string
            {
                return '11111111-2222-4333-8444-555555555555';
            }

            public function interactiveCommand(string $agent, string $prompt, ?string $model): ?string
            {
                return null;
            }

            public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string
            {
                $this->sessionSeen = $sessionId;

                return 'true';
            }

            public function followUpCommand(string $sessionId, string $prompt, ?string $model): string
            {
                return 'true';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function sessions(): \Pablo\Agents\Runtime\RuntimeSessionsInterface
            {
                return new \Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions();
            }

            public function agentFiles(): \Pablo\Agents\Runtime\RuntimeAgentFilesInterface
            {
                return (new OpenCodeRuntime(\Pablo\Tests\Registries::templates()))->agentFiles();
            }

            public function usage(\Pablo\Support\ProcessRunnerInterface $runner): \Pablo\Analytics\UsageHarvesterInterface
            {
                return (new OpenCodeRuntime(\Pablo\Tests\Registries::templates()))->usage($runner);
            }

            public function oneShot(): \Pablo\Agents\Runtime\RuntimeOneShotInterface
            {
                return (new OpenCodeRuntime(\Pablo\Tests\Registries::templates()))->oneShot();
            }

            public function probe(): \Pablo\Doctor\CliProbe
            {
                return (new OpenCodeRuntime(\Pablo\Tests\Registries::templates()))->probe();
            }

            public function modelError(string $model): ?string
            {
                return null;
            }
        };
        mkdir($this->tmp.'/agents', 0o777, true);
        $agents = new OrcaLauncher(new FakeProcessRunner(), new \Pablo\Domain\Time(), $runtime);

        $handle = $agents->launchHeadless($this->tmp, 'task-analyst', 'p', 'sonnet');
        $this->assertStringStartsWith('pid:', $handle);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $runtime->sessionSeen);
        $record = json_decode((string) file_get_contents($this->tmp.'/agents/'.substr($handle, 4).'.json'), true);
        $this->assertIsArray($record);
        $this->assertSame('11111111-2222-4333-8444-555555555555', $record['session']);
        $this->assertSame('claude-code', $record['runtime']);

        // A caller-assigned id (the rebase resolver's) wins.
        $agents->launchHeadless($this->tmp, 'rebase-conflict-resolver', 'p', null, 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee');
        $this->assertSame('aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee', $runtime->sessionSeen);
    }
}
