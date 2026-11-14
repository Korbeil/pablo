<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\Copilot\CopilotAgents;
use Pablo\Agents\Runtime\Copilot\CopilotRuntime;
use Pablo\Agents\Runtime\FollowUpPolicy;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use PHPUnit\Framework\TestCase;

/**
 * YOLO mode's follow-up runs: the analyst's session continued as the
 * runtime's own build agent, unattended, FollowUpPolicy denied where the
 * runtime takes per-run rules.
 */
final class FollowUpCommandTest extends TestCase
{
    private static function templates(): AgentTemplates
    {
        return new AgentTemplates(new Config(new GlobalConfig()));
    }

    public function testOpenCodeContinuesTheSessionAsBuildWithAuto(): void
    {
        $runtime = new OpenCodeRuntime(self::templates());

        $this->assertSame(
            "opencode run -s 'ses_x' --agent build --auto 'Do it'",
            $runtime->followUpCommand('ses_x', 'Do it', null),
        );
        $this->assertSame(
            "opencode run -s 'ses_x' --model 'p/m' --agent build --auto 'Do it'",
            $runtime->followUpCommand('ses_x', 'Do it', 'p/m'),
        );
    }

    public function testClaudeCodeResumesWithEditsAcceptedAndThePolicyDenied(): void
    {
        $runtime = new ClaudeCodeRuntime(new ClaudeCodeAgents(self::templates()), '/home/u/.pablo/runtime/claude');

        $command = $runtime->followUpCommand('11111111-2222-4333-8444-555555555555', 'Do it', 'opus');

        $this->assertStringStartsWith('claude -p --resume 11111111-2222-4333-8444-555555555555 --model opus --strict-mcp-config --permission-mode acceptEdits --allowedTools Read Grep Glob Bash Edit Write ', $command);
        $this->assertStringNotContainsString('--agent', $command, 'the main agent, not the analyst');
        $this->assertStringContainsString("--disallowedTools 'Bash(git config*)' 'Bash(rtk git config*)'", $command);
        $this->assertStringContainsString("'Bash(gh pr edit*)'", $command);
        $this->assertStringEndsWith(" -- 'Do it'", $command);
    }

    public function testCopilotResumesWithEveryToolButThePolicy(): void
    {
        $runtime = new CopilotRuntime(new CopilotAgents(self::templates()));

        $command = $runtime->followUpCommand('11111111-2222-4333-8444-555555555555', 'Do it', null);

        $this->assertStringStartsWith("copilot '--resume=11111111-2222-4333-8444-555555555555' --no-ask-user --no-auto-update --no-color --disable-builtin-mcps --allow-all-tools ", $command);
        $this->assertStringContainsString("--deny-tool 'shell(git config)' --deny-tool 'shell(git config:*)'", $command);
        $this->assertStringContainsString("--deny-tool 'shell(rtk gh api:*)'", $command);
        $this->assertStringEndsWith(" -p 'Do it'", $command);
    }

    public function testEveryPolicyPatternTranslatesForCopilot(): void
    {
        foreach (FollowUpPolicy::DENIED_SHELL as $pattern) {
            $this->assertNotSame([], CopilotAgents::shellRules($pattern, 'yolo follow-up'), $pattern);
        }
    }
}
