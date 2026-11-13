<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentTranslation;
use PHPUnit\Framework\TestCase;

final class AgentTranslationTest extends TestCase
{
    private string $tmp = '';

    protected function tearDown(): void
    {
        if ('' !== $this->tmp) {
            @unlink($this->tmp);
        }
    }

    public function testAgentIdsArePrefixed(): void
    {
        $this->assertSame('pablo-ci-analyst', AgentTranslation::agentId('ci-analyst'));
    }

    public function testAgentFilesCoverEveryAgentKeyedByIdAndSuffix(): void
    {
        $files = AgentTranslation::agentFiles('.agent.md', static fn (string $name, string $id): string => "{$name}|{$id}");

        $expected = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $expected['pablo-'.$name.'.agent.md'] = $name.'|pablo-'.$name;
        }
        $this->assertSame($expected, $files);
    }

    public function testReadCommandSplitsNameFrontmatterAndBody(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-cmd-'.uniqid().'.md';
        file_put_contents($this->tmp, "---\ndescription: Do it\n---\nBody\n");

        [$name, $front, $body] = AgentTranslation::readCommand($this->tmp);

        $this->assertSame(basename($this->tmp, '.md'), $name);
        $this->assertSame(['description' => 'Do it'], $front);
        $this->assertSame("Body\n", $body);
    }

    public function testReadCommandFailsOnAMissingFileAsATemplateError(): void
    {
        $missing = sys_get_temp_dir().'/pablo-no-such-command-'.uniqid().'.md';

        $this->expectException(AgentTemplateError::class);
        $this->expectExceptionMessage("Cannot read command: {$missing}");
        AgentTranslation::readCommand($missing);
    }

    public function testNoEditsRuleOpensTheHardRulesOnce(): void
    {
        $body = "Intro\n\n## Hard rules\n\n- Existing rule.\n\n## Hard rules\n\n- Other.\n";

        $this->assertSame(
            "Intro\n\n## Hard rules\n\n"
            ."- **No code edits.** This skill only commits what is already in\n"
            ."  the worktree: never edit or create files, except writing\n"
            ."  `.pablo-pr-body.md` for the PR body.\n"
            ."- Existing rule.\n\n## Hard rules\n\n- Other.\n",
            AgentTranslation::withNoEditsRule($body, 'skill'),
        );
        $this->assertSame("No rules here.\n", AgentTranslation::withNoEditsRule("No rules here.\n", 'command'));
    }
}
