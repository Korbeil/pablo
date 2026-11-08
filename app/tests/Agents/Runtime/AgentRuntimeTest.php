<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\Agents;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Agents\Runtime\SessionRef;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
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
        $this->tmp = sys_get_temp_dir().'/pablo-runtime-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        putenv('PABLO_AGENTS_DIR='.$this->tmp.'/agents');
        putenv('PABLO_AGENT_RUNTIME');
        putenv('PABLO_AGENT_BACKEND');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        putenv('PABLO_AGENT_RUNTIME');
        putenv('PABLO_AGENT_BACKEND');
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
        $runtime = new OpenCodeRuntime();
        $this->assertSame("opencode run --model 'a/b' --agent task-analyst 'Analyze #1'", $runtime->launchCommand('task-analyst', 'Analyze #1', 'a/b'));
        $this->assertSame("opencode run --agent task-analyst 'x'", $runtime->launchCommand('task-analyst', 'x', null));
        $this->assertNull($runtime->newSessionId());
        $this->assertSame('opencode -s ses_1', $runtime->sessions()->resumeCommand('ses_1'));
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
        $resolver = new AgentRuntimeRegistry(new GlobalConfig());
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
        (new AgentRuntimeRegistry(new GlobalConfig()))->resolveName();
    }

    public function testFactoryRefusesOpenChamberWithClaudeCode(): void
    {
        $this->writeGlobalConfig("agent_runtime: claude-code\nagent_backend: openchamber\n");
        $factory = new AgentLauncherFactory(new GlobalConfig());
        try {
            $factory->create();
            $this->fail('OpenChamber + Claude Code must be refused');
        } catch (PabloError $e) {
            $this->assertStringContainsString('OpenChamber only works with OpenCode', $e->getMessage());
        }
        // Orca runs either runtime.
        $launcher = $factory->create('orca');
        $this->assertInstanceOf(Agents::class, $launcher);
        $this->assertSame(ClaudeCodeRuntime::NAME, $launcher->runtime()->name());
        $this->assertNull((new OpenCodeRuntime())->backendError('openchamber'));
    }

    public function testOrcaLaunchesTheClaudeCommandInTheWorktree(): void
    {
        $calls = [];
        $runner = new FakeProcessRunner();
        $runner->onRun = static function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return json_encode(['id' => 'x', 'ok' => true, 'result' => ['handle' => 'term_9']], \JSON_THROW_ON_ERROR);
        };
        $agents = new Agents($runner, new \Pablo\Domain\Time(), null, '/x/pablo', $this->claude());

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

            public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string
            {
                $this->sessionSeen = $sessionId;

                return 'true';
            }

            public function label(): string
            {
                return 'Fake';
            }

            public function backendError(string $backend): ?string
            {
                return null;
            }

            public function sessions(): \Pablo\Agents\Runtime\RuntimeSessionsInterface
            {
                return new \Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions();
            }

            public function agentFiles(): \Pablo\Agents\Runtime\RuntimeAgentFilesInterface
            {
                return (new OpenCodeRuntime())->agentFiles();
            }

            public function usage(\Pablo\Support\ProcessRunnerInterface $runner): \Pablo\Analytics\UsageHarvesterInterface
            {
                return (new OpenCodeRuntime())->usage($runner);
            }

            public function oneShot(): \Pablo\Agents\Runtime\RuntimeOneShotInterface
            {
                return (new OpenCodeRuntime())->oneShot();
            }

            public function probe(): \Pablo\Agents\Runtime\RuntimeProbe
            {
                return (new OpenCodeRuntime())->probe();
            }

            public function modelError(string $model): ?string
            {
                return null;
            }
        };
        mkdir($this->tmp.'/agents', 0o777, true);
        $agents = new Agents(new FakeProcessRunner(), new \Pablo\Domain\Time(), null, '/x/pablo', $runtime);

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
