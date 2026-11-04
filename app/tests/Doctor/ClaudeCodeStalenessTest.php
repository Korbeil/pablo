<?php

declare(strict_types=1);

namespace Pablo\Tests\Doctor;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\ClaudeCodeAgents;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\AgentFileState;
use Pablo\Doctor\AgentStaleness;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

/**
 * Doctor's agent check under agent_runtime: claude-code — agents.json in
 * ~/.pablo/claude, the command and skill symlinks in ~/.claude.
 */
final class ClaudeCodeStalenessTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private AgentTemplates $templates;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-cc-stale-'.uniqid();
        mkdir($this->tmp.'/pablo-claude', 0o777, true);
        mkdir($this->tmp.'/claude/commands', 0o777, true);
        mkdir($this->tmp.'/claude/skills', 0o777, true);
        mkdir($this->tmp.'/rendered', 0o777, true);
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/no-projects');
        $this->writeGlobalConfig("agent_runtime: claude-code\n");
        $this->templates = new AgentTemplates(new Config(new GlobalConfig()));
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
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

    /** Install exactly what generate-agents + install.sh would. */
    private function install(): void
    {
        $claude = new ClaudeCodeAgents($this->templates);
        $agentsDir = $this->templates->repoAgentsDir();
        file_put_contents($this->tmp.'/pablo-claude/agents.json', $claude->renderAgentsJson($agentsDir, $this->templates->enabledProviders()));
        mkdir($this->tmp.'/claude/agents', 0o777, true);
        foreach ($claude->renderAgentFiles($agentsDir, $this->templates->enabledProviders()) as $file => $content) {
            file_put_contents($this->tmp.'/rendered/'.$file, $content);
            symlink($this->tmp.'/rendered/'.$file, $this->tmp.'/claude/agents/'.$file);
        }
        $command = $this->tmp.'/rendered/pablo-commit-and-pr.md';
        file_put_contents($command, $claude->renderCommand(AgentTemplates::commandsDirFor($agentsDir).'/pablo-commit-and-pr.md'));
        symlink($command, $this->tmp.'/claude/commands/pablo-commit-and-pr.md');
        symlink(\dirname($agentsDir).'/skills/pablo-task', $this->tmp.'/claude/skills/pablo-task');
    }

    /** @return array<string, AgentFileState> every pablo-<agent> row in $state */
    private static function agentStates(AgentFileState $state): array
    {
        $states = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $states[ClaudeCodeAgents::agentId($name)] = $state;
        }

        return $states;
    }

    /** @return array<string, AgentFileState> */
    private function states(): array
    {
        $staleness = new AgentStaleness($this->templates);
        $states = [];
        foreach ($staleness->checkClaudeCode($this->tmp.'/pablo-claude', $this->tmp.'/claude') as $result) {
            $states[$result->agent] = $result->state;
        }

        return $states;
    }

    public function testFreshInstallIsOk(): void
    {
        $this->install();
        $this->assertSame([
            'agents.json' => AgentFileState::Ok,
            ...self::agentStates(AgentFileState::Ok),
            '/pablo-commit-and-pr' => AgentFileState::Ok,
            'skill pablo-task' => AgentFileState::Ok,
        ], $this->states());
    }

    public function testCheckFollowsTheRuntime(): void
    {
        $this->install();
        putenv('PABLO_CLAUDE_DIR='.$this->tmp.'/pablo-claude');
        putenv('CLAUDE_CONFIG_DIR='.$this->tmp.'/claude');
        try {
            $results = (new AgentStaleness($this->templates))->check();
        } finally {
            putenv('PABLO_CLAUDE_DIR');
            putenv('CLAUDE_CONFIG_DIR');
        }
        $this->assertSame(['agents.json', ...array_keys(self::agentStates(AgentFileState::Ok)), '/pablo-commit-and-pr', 'skill pablo-task'], array_map(static fn ($r) => $r->agent, $results));
    }

    public function testNothingInstalledIsAHardFailure(): void
    {
        $states = $this->states();
        $this->assertSame(AgentFileState::Missing, $states['agents.json']);
        $this->assertSame(AgentFileState::Missing, $states['pablo-task-analyst']);
        $this->assertSame(AgentFileState::Missing, $states['/pablo-commit-and-pr']);
        $this->assertSame(AgentFileState::Missing, $states['skill pablo-task']);
        $staleness = new AgentStaleness($this->templates);
        $this->assertTrue($staleness->hasHardFailure($staleness->checkClaudeCode($this->tmp.'/pablo-claude', $this->tmp.'/claude')));
    }

    public function testOutdatedFilesAreStaleAndForeignFilesAreReported(): void
    {
        $this->install();
        file_put_contents($this->tmp.'/pablo-claude/agents.json', "{}\n");
        file_put_contents($this->tmp.'/rendered/pablo-commit-and-pr.md', "old\n");
        file_put_contents($this->tmp.'/rendered/pablo-task-analyst.md', "old\n");
        unlink($this->tmp.'/claude/skills/pablo-task');
        mkdir($this->tmp.'/claude/skills/pablo-task');

        $states = $this->states();
        $this->assertSame(AgentFileState::Stale, $states['agents.json']);
        $this->assertSame(AgentFileState::Stale, $states['pablo-task-analyst']);
        $this->assertSame(AgentFileState::Stale, $states['/pablo-commit-and-pr']);
        $this->assertSame(AgentFileState::ForeignFile, $states['skill pablo-task']);
    }
}
