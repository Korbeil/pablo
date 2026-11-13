<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime\Copilot;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\Copilot\CopilotAgentFiles;
use Pablo\Agents\Runtime\Copilot\CopilotAgents;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\AgentFileState;
use Pablo\Doctor\AgentStaleness;
use Pablo\Tests\TempDir;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

/**
 * Doctor's agent check under agent_runtime: copilot — the pablo-<agent>
 * symlinks in ~/.copilot/agents and the skill symlinks in ~/.copilot/skills.
 */
final class CopilotAgentFilesTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private AgentTemplates $templates;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('copilot-stale');
        mkdir($this->tmp.'/copilot/agents', 0o777, true);
        mkdir($this->tmp.'/copilot/skills', 0o777, true);
        mkdir($this->tmp.'/rendered/agents', 0o777, true);
        mkdir($this->tmp.'/rendered/skills/pablo-commit-and-pr', 0o777, true);
        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/no-projects');
        $this->writeGlobalConfig("agent_runtime: copilot\n");
        $this->templates = new AgentTemplates(new Config(new GlobalConfig()));
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        putenv('COPILOT_HOME');
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
        $copilot = new CopilotAgents($this->templates);
        $agentsDir = $this->templates->templatesDir();
        foreach ($copilot->renderAgentFiles($agentsDir, $this->templates->enabledProviders()) as $file => $content) {
            file_put_contents($this->tmp.'/rendered/agents/'.$file, $content);
            symlink($this->tmp.'/rendered/agents/'.$file, $this->tmp.'/copilot/agents/'.$file);
        }
        file_put_contents(
            $this->tmp.'/rendered/skills/pablo-commit-and-pr/SKILL.md',
            $copilot->renderSkill(AgentTemplates::commandsDirFor($agentsDir).'/pablo-commit-and-pr.md'),
        );
        symlink($this->tmp.'/rendered/skills/pablo-commit-and-pr', $this->tmp.'/copilot/skills/pablo-commit-and-pr');
        symlink(\dirname($agentsDir).'/skills/pablo-task', $this->tmp.'/copilot/skills/pablo-task');
    }

    /** @return list<\Pablo\Doctor\AgentFileStatus> */
    private function staleness(): array
    {
        $files = new CopilotAgentFiles(new CopilotAgents($this->templates), null, $this->tmp.'/copilot');

        return $files->staleness($this->templates->templatesDir(), $this->templates->enabledProviders());
    }

    /** @return array<string, AgentFileState> */
    private function states(): array
    {
        $states = [];
        foreach ($this->staleness() as $result) {
            $states[$result->agent] = $result->state;
        }

        return $states;
    }

    public function testFreshInstallIsOk(): void
    {
        $this->install();
        $states = $this->states();
        $this->assertSame([
            'pablo-task-analyst', 'pablo-task-feedback', 'pablo-ci-analyst', 'pablo-pr-feedback', 'pablo-rebase-conflict-resolver',
            'skill pablo-commit-and-pr', 'skill pablo-task',
        ], array_keys($states));
        $this->assertSame([AgentFileState::Ok], array_values(array_unique($states, \SORT_REGULAR)));
    }

    public function testCheckFollowsTheRuntime(): void
    {
        $this->install();
        putenv('COPILOT_HOME='.$this->tmp.'/copilot');
        $results = (new AgentStaleness($this->templates))->check();
        $this->assertSame('pablo-task-analyst', $results[0]->agent);
        $this->assertSame(AgentFileState::Ok, $results[0]->state);
    }

    public function testMissingStaleAndForeignAreReported(): void
    {
        $staleness = new AgentStaleness($this->templates);
        $this->assertTrue($staleness->hasHardFailure($this->staleness()));
        $this->assertSame(AgentFileState::Missing, $this->states()['pablo-ci-analyst']);

        $this->install();
        file_put_contents($this->tmp.'/rendered/agents/pablo-ci-analyst.agent.md', "old\n");
        file_put_contents($this->tmp.'/rendered/skills/pablo-commit-and-pr/SKILL.md', "old\n");
        unlink($this->tmp.'/copilot/skills/pablo-task');
        mkdir($this->tmp.'/copilot/skills/pablo-task');

        $states = $this->states();
        $this->assertSame(AgentFileState::Stale, $states['pablo-ci-analyst']);
        $this->assertSame(AgentFileState::Stale, $states['skill pablo-commit-and-pr']);
        $this->assertSame(AgentFileState::ForeignFile, $states['skill pablo-task']);
        $this->assertSame(AgentFileState::Ok, $states['pablo-task-analyst']);
    }
}
