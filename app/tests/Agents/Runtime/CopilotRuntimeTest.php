<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\Agents;
use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeResolver;
use Pablo\Agents\Runtime\CopilotAgents;
use Pablo\Agents\Runtime\CopilotRuntime;
use Pablo\Agents\Runtime\SessionRef;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Copilot runtime: the translation of the real repo templates (every
 * OpenCode guardrail must survive it, raw and RTK-rewritten), the commit
 * skill, and the exact `copilot -p` commands. Nothing here ever executes
 * `copilot`.
 */
final class CopilotRuntimeTest extends TestCase
{
    use UsesGlobalConfig;

    private AgentTemplates $templates;
    private CopilotAgents $copilot;

    protected function setUp(): void
    {
        putenv('PABLO_AGENT_RUNTIME');
        putenv('PABLO_AGENT_BACKEND');
        $this->templates = new AgentTemplates(new Config(new GlobalConfig()));
        $this->copilot = new CopilotAgents($this->templates);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENT_RUNTIME');
        putenv('PABLO_AGENT_BACKEND');
        $this->unsetGlobalConfig();
    }

    private function runtime(): CopilotRuntime
    {
        return new CopilotRuntime($this->copilot);
    }

    /** @return iterable<string, array{0: string}> */
    public static function agents(): iterable
    {
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            yield $name => [$name];
        }
    }

    /** @return list<array{action: string, resource: string, effect: string}> */
    private function openCodeRules(string $agent): array
    {
        $markdown = $this->templates->render($this->templates->repoAgentsDir(), $agent, Config::PROVIDERS);
        if (1 !== preg_match('/\A---\R(.*?)\R---/s', $markdown, $m)) {
            self::fail('no frontmatter');
        }
        $front = Yaml::parse($m[1]);
        $this->assertIsArray($front);
        $rules = [];
        foreach ((array) $front['permissions'] as $rule) {
            $this->assertIsArray($rule);
            $rules[] = ['action' => (string) $rule['action'], 'resource' => (string) $rule['resource'], 'effect' => (string) $rule['effect']];
        }

        return $rules;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agents')]
    public function testEveryShellDenyHasItsRawAndRtkForm(string $agent): void
    {
        $profile = $this->copilot->profile($agent, null, Config::PROVIDERS);
        $denies = 0;
        foreach ($this->openCodeRules($agent) as $rule) {
            if ('shell' !== $rule['action'] || '*' === $rule['resource'] || 'deny' !== $rule['effect']) {
                continue;
            }
            ++$denies;
            $prefix = trim($rule['resource'], '* ');
            foreach (["shell({$prefix})", "shell({$prefix}:*)", "shell(rtk {$prefix})", "shell(rtk {$prefix}:*)"] as $expected) {
                $this->assertContains($expected, $profile->denied, "{$agent}: {$expected}");
            }
        }
        $this->assertGreaterThan(0, $denies, "{$agent} has shell denies to translate");
        $this->assertSame('pablo-'.$agent, $profile->id);
        $this->assertNotContains('skill', $profile->availableTools, 'skills are denied to every agent');
    }

    public function testAnalystsAreReadOnlyAndTheResolverMayEdit(): void
    {
        $analyst = $this->copilot->profile('task-analyst');
        $this->assertSame(['view', 'grep', 'glob', 'web_fetch', 'bash'], $analyst->availableTools);
        $this->assertSame(['read', 'search', 'web', 'execute'], $analyst->toolAliases);
        $this->assertSame(['read', 'shell'], $analyst->allowed);
        $this->assertContains('write', $analyst->denied);
        $this->assertTrue($analyst->allowAllUrls);
        $this->assertStringContainsString('## Step budget', $analyst->prompt);

        $resolver = $this->copilot->profile('rebase-conflict-resolver');
        $this->assertContains('edit', $resolver->availableTools);
        $this->assertContains('write', $resolver->allowed);
        $this->assertNotContains('write', $resolver->denied);
        $this->assertFalse($resolver->allowAllUrls);
        $this->assertContains('shell(gh pr merge:*)', $resolver->denied);
        $this->assertContains('shell(acli jira workitem edit)', $resolver->denied, 'leading * dropped');
    }

    public function testAgentFileHasNoModelAndOnlyVisibleTools(): void
    {
        $file = $this->copilot->profile('ci-analyst')->definition();
        $this->assertStringStartsWith("---\nname: pablo-ci-analyst\ndescription: \"", $file);
        $this->assertStringContainsString("\ntools: [\"read\",\"search\",\"web\",\"execute\"]\n---\n", $file);
        $this->assertStringNotContainsString("\nmodel:", $file, 'a model line would beat --model');
        $this->assertSame(
            ['pablo-task-analyst.agent.md', 'pablo-task-feedback.agent.md', 'pablo-ci-analyst.agent.md', 'pablo-pr-feedback.agent.md', 'pablo-rebase-conflict-resolver.agent.md'],
            array_keys($this->copilot->renderAgentFiles($this->templates->repoAgentsDir(), Config::PROVIDERS)),
        );
    }

    public function testUntranslatablePatternsAreRefused(): void
    {
        $this->expectException(AgentTemplateError::class);
        CopilotAgents::shellRules('git *push', 'x');
    }

    public function testCommitCommandBecomesAManualOnlySkill(): void
    {
        $skill = $this->copilot->renderSkill(AgentTemplates::commandsDirFor($this->templates->repoAgentsDir()).'/pablo-commit-and-pr.md');
        $this->assertStringStartsWith("---\nname: pablo-commit-and-pr\ndescription: ", $skill);
        $this->assertStringContainsString("disable-model-invocation: true\n", $skill, 'the model never commits on its own');
        $this->assertStringNotContainsString('!`', $skill, 'no ! context injection in Copilot');
        $this->assertStringContainsString("Current branch:\n`git branch --show-current`", $skill);
        $this->assertStringContainsString('## Context (gather it first)', $skill);
        $this->assertStringNotContainsString('$ARGUMENTS', $skill);
        $this->assertStringContainsString('- **No code edits.**', $skill);
    }

    public function testLaunchCommandCarriesAgentSessionAndGuardrails(): void
    {
        $runtime = $this->runtime();
        $sid = $runtime->newSessionId();
        $this->assertTrue(SessionRef::isClaudeSession($sid), 'a UUID');

        $command = $runtime->launchCommand('task-analyst', "Analyze issue #45 — it's urgent", 'claude-sonnet-5.5', $sid);
        $this->assertStringStartsWith("copilot --agent pablo-task-analyst --model claude-sonnet-5.5 --session-id {$sid} --no-ask-user --no-auto-update --no-color --disable-builtin-mcps --available-tools 'view,grep,glob,web_fetch,bash' --allow-tool read --allow-tool shell --allow-all-urls --deny-tool write ", $command);
        $this->assertStringContainsString("--deny-tool 'shell(git push:*)' --deny-tool 'shell(rtk git push)'", $command);
        $this->assertStringEndsWith(' -p '.escapeshellarg("Analyze issue #45 — it's urgent"), $command);

        $resolver = $runtime->launchCommand('rebase-conflict-resolver', 'fix', null);
        $this->assertStringContainsString('--allow-tool write', $resolver);
        $this->assertStringNotContainsString('--model', $resolver);
        $this->assertStringNotContainsString('--session-id', $resolver);
    }

    public function testModelsAndSessionRefs(): void
    {
        $runtime = $this->runtime();
        $this->assertNull($runtime->modelError('claude-sonnet-5.5'));
        $this->assertNull($runtime->modelError('gpt-5.3-codex'));
        $this->assertStringContainsString('OpenCode provider/model id', (string) $runtime->modelError('opencode-go/glm-5.3-flash'));
        $this->assertStringContainsString('Claude Code model name', (string) $runtime->modelError('sonnet'));
        $this->assertStringContainsString('claude-sonnet-5.5', (string) $runtime->modelError('claude-sonnet-5-5'));

        $sid = '0fc45506-985b-4296-be02-a1039fb8be47';
        $this->assertSame('copilot --resume='.$sid, $runtime->sessionRef($sid));
        $handle = SessionRef::handle(AgentRuntimeInterface::COPILOT, $sid);
        $this->assertSame('copilot:'.$sid, $handle);
        // Every runtime's handle still reads right, whichever runtime is selected now.
        $this->assertSame('copilot --resume='.$sid, SessionRef::command($handle));
        $this->assertSame('claude --resume '.$sid, SessionRef::command(SessionRef::handle(AgentRuntimeInterface::CLAUDE_CODE, $sid)));
        $this->assertSame('opencode -s ses_9', SessionRef::command('ses_9'));
    }

    public function testResolverAndFactory(): void
    {
        $this->writeGlobalConfig("agent_runtime: copilot\n");
        $resolver = new AgentRuntimeResolver(new GlobalConfig());
        $this->assertSame('copilot', $resolver->resolveName());
        $this->assertInstanceOf(CopilotRuntime::class, $resolver->resolve());

        $factory = new AgentLauncherFactory(new GlobalConfig());
        $launcher = $factory->create('orca');
        $this->assertInstanceOf(Agents::class, $launcher);
        $this->assertSame(AgentRuntimeInterface::COPILOT, $launcher->runtime()->name());

        $this->writeGlobalConfig("agent_runtime: copilot\nagent_backend: openchamber\n");
        try {
            $factory->create();
            $this->fail('OpenChamber + Copilot must be refused');
        } catch (PabloError $e) {
            $this->assertStringContainsString('agent_runtime copilot cannot run on agent_backend openchamber', $e->getMessage());
        }
    }

    public function testOrcaLaunchesTheCopilotCommandInTheWorktree(): void
    {
        $calls = [];
        $runner = new FakeProcessRunner();
        $runner->onRun = static function (array $argv) use (&$calls): string {
            $calls[] = $argv;

            return json_encode(['id' => 'x', 'ok' => true, 'result' => ['handle' => 'term_9']], \JSON_THROW_ON_ERROR);
        };
        $wt = sys_get_temp_dir();
        $agents = new Agents($runner, new \Pablo\Domain\Time(), null, '/x/pablo', $this->runtime());

        $this->assertSame('term_9', $agents->doLaunchAgent($wt, 'ci-analyst', 'Why is CI red?', 'gpt-5.3-codex'));
        $key = array_search('--command', $calls[1], true);
        $this->assertIsInt($key);
        $command = $calls[1][$key + 1];
        $this->assertStringStartsWith('cd '.escapeshellarg($wt).'; copilot --agent pablo-ci-analyst --model gpt-5.3-codex --session-id ', $command);
        $this->assertStringEndsWith(" -p 'Why is CI red?'", $command);
    }
}
