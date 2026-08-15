<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\GenerateAgentsCommand;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class GenerateAgentsCommandTest extends TestCase
{
    use UsesGlobalConfig;

    private string $tmp;
    private string $agentsDir;
    private string $projectsDir;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-gen-agents-'.uniqid();
        $this->agentsDir = $this->tmp.'/agents';
        $this->projectsDir = $this->tmp.'/projects';
        mkdir($this->agentsDir, 0o777, true);
        mkdir($this->projectsDir, 0o777, true);
        $this->copyProviders();

        putenv('PABLO_PROJECTS_DIR='.$this->projectsDir);

        $this->writeGlobalConfig(<<<'YAML'
sync:
  strategy: rebase
  auto_apply: false
  interval_minutes: 30
state_polling:
  interval_minutes: 10
review:
  bot_whitelist: []
ci:
  ignore_checks: []
default_model: openrouter/test/model
pr_description_locale: en
YAML);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_PROJECTS_DIR');
        $this->unsetGlobalConfig();
        $this->removeDir($this->tmp);
    }

    public function testGeneratesWithAllProvidersWhenNoProjectsDir(): void
    {
        $this->copyTemplate('task-analyst');
        $this->copyTemplate('task-feedback');

        $command = new GenerateAgentsCommand();
        $tester = new CommandTester($command);

        putenv('PABLO_PROJECTS_DIR='.$this->tmp.'/nonexistent');
        $tester->execute(['--agents-dir' => $this->agentsDir]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('all providers enabled', $display);
        $this->assertStringContainsString('task-analyst.md with providers: github, jira, linear', $display);
        $this->assertStringContainsString('task-feedback.md with providers: github, jira, linear', $display);

        $this->assertSectionHas('task-analyst', ['GitHub', 'Jira', 'Linear', 'Confluence']);
        $this->assertSectionHas('task-feedback', ['GitHub', 'Jira', 'Linear', 'Confluence']);
    }

    public function testGeneratesWithJiraOnly(): void
    {
        $this->writeProject('jira-project', 'jira');
        $this->copyTemplate('task-analyst');
        $this->copyTemplate('task-feedback');

        $command = new GenerateAgentsCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--agents-dir' => $this->agentsDir]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('task-analyst.md with providers: jira', $display);
        $this->assertStringContainsString('task-feedback.md with providers: jira', $display);

        $this->assertSectionHas('task-analyst', ['Jira', 'Confluence']);
        $this->assertSectionHas('task-feedback', ['Jira', 'Confluence']);

        $this->assertSectionNotHas('task-analyst', ['GitHub', 'Linear']);
        $this->assertSectionNotHas('task-feedback', ['GitHub', 'Linear']);
    }

    public function testGeneratesWithGithubAndLinear(): void
    {
        $this->writeProject('gh-project', 'github');
        $this->writeProject('lin-project', 'linear');
        $this->copyTemplate('task-analyst');
        $this->copyTemplate('task-feedback');

        $command = new GenerateAgentsCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--agents-dir' => $this->agentsDir]);

        $display = $tester->getDisplay();
        $this->assertStringContainsString('task-analyst.md with providers: github, linear', $display);

        $this->assertSectionHas('task-analyst', ['GitHub', 'Linear']);
        $this->assertSectionHas('task-feedback', ['GitHub', 'Linear']);

        $this->assertSectionNotHas('task-analyst', ['Jira', 'Confluence']);
        $this->assertSectionNotHas('task-feedback', ['Jira', 'Confluence']);
    }

    public function testFillsProviderNamesPlaceholder(): void
    {
        $this->writeProject('p1', 'github');
        $this->writeProject('p2', 'jira');
        $this->copyTemplate('task-analyst');

        $command = new GenerateAgentsCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--agents-dir' => $this->agentsDir]);

        $content = file_get_contents($this->agentsDir.'/task-analyst.md');
        \assert(false !== $content);
        $this->assertStringContainsString('GitHub Issues, or Jira', $content);
        $this->assertStringNotContainsString('{{ISSUE_TRACKER_NAMES}}', $content);
        $this->assertStringNotContainsString('{{ISSUE_TRACKER_SECTION}}', $content);
    }

    public function testReturnsFailureOnMissingTemplate(): void
    {
        $command = new GenerateAgentsCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--agents-dir' => $this->agentsDir]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Template not found', $tester->getDisplay());
    }

    public function testProviderEnumerationFromProjects(): void
    {
        $this->writeProject('a', 'github');
        $this->writeProject('b', 'jira');
        $this->writeProject('c', 'linear');
        $this->copyTemplate('task-analyst');
        $this->copyTemplate('task-feedback');

        $command = new GenerateAgentsCommand();
        $tester = new CommandTester($command);
        $tester->execute(['--agents-dir' => $this->agentsDir]);

        $this->assertStringContainsString('providers: github, jira, linear', $tester->getDisplay());
    }

    /** @param list<string> $expectedSections */
    private function assertSectionHas(string $name, array $expectedSections): void
    {
        $section = $this->extractSection($name);
        foreach ($expectedSections as $sectionName) {
            $label = match ($sectionName) {
                'Confluence' => 'Confluence documentation',
                default => $sectionName,
            };
            $this->assertStringContainsString("**{$label}**", $section);
        }
    }

    /** @param list<string> $unexpectedSections */
    private function assertSectionNotHas(string $name, array $unexpectedSections): void
    {
        $section = $this->extractSection($name);
        foreach ($unexpectedSections as $sectionName) {
            $label = match ($sectionName) {
                'Confluence' => 'Confluence',
                default => $sectionName,
            };
            $this->assertStringNotContainsString("**{$label}", $section);
        }
    }

    private function extractSection(string $name): string
    {
        $path = $this->agentsDir.'/'.$name.'.md';
        $this->assertFileExists($path);
        $content = file_get_contents($path);
        \assert(false !== $content);

        $startStr = str_contains($name, 'feedback')
            ? '## Retrieving the ticket and QA comments'
            : '## Retrieving the ticket';

        $start = strpos($content, $startStr);
        if (false === $start) {
            return '';
        }

        $rest = substr($content, $start + \strlen($startStr));
        $end = strpos($rest, "\n## ");
        if (false === $end) {
            return $rest;
        }

        return substr($rest, 0, $end);
    }

    private function writeProject(string $name, string $provider): void
    {
        file_put_contents($this->projectsDir.'/'.$name.'.yaml', <<<YAML
name: {$name}
type: work
repo:
  path: /tmp/{$name}
  primary_branch: main
issue_tracker:
  provider: {$provider}
  identity: u@e.com
  project_key: XX
YAML);
    }

    private function copyTemplate(string $name): void
    {
        $repoTemplate = \dirname(__DIR__, 4).'/opencode/agents/'.$name.'.md.template';
        copy($repoTemplate, $this->agentsDir.'/'.$name.'.md.template');
    }

    private function copyProviders(): void
    {
        $repoProviders = \dirname(__DIR__, 4).'/opencode/agents/providers';
        $dest = $this->agentsDir.'/providers';
        if (!is_dir($dest)) {
            mkdir($dest, 0o777, true);
        }
        foreach (glob($repoProviders.'/*.md') ?: [] as $file) {
            copy($file, $dest.'/'.basename($file));
        }
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}
