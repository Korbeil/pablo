<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Command\Report\ProjectsCommand;
use Pablo\Tests\Command\CommandTestBed;

final class ProjectsCommandTest extends CommandTestBed
{
    private function command(): ProjectsCommand
    {
        return new ProjectsCommand($this->store, $this->projectsLoader);
    }

    public function testListsTheValidProjectsAndWarnsAboutTheBrokenOne(): void
    {
        file_put_contents($this->projectsDir.'/broken.yaml', "name: broken\ntype: work\n");

        $tester = $this->runCommand($this->command());

        $this->assertSame(0, $tester->getStatusCode());
        $display = $tester->getDisplay();
        $this->assertStringContainsString("wallet-kit\topen-source\tgithub\t{$this->tmp}/repo", $display);
        $this->assertStringContainsString("pablo: warning: skipping invalid project config {$this->projectsDir}/broken.yaml: broken.yaml: missing required key 'repo.path'", $display);
    }

    public function testNoWarningWhenEveryProjectLoads(): void
    {
        $tester = $this->runCommand($this->command());

        $this->assertStringNotContainsString('warning', $tester->getDisplay());
    }

    public function testTableIsTabSeparated(): void
    {
        $tester = $this->runCommand($this->command());

        $this->assertSame("wallet-kit\topen-source\tgithub\t{$this->tmp}/repo\n", $tester->getDisplay());
    }

    public function testJson(): void
    {
        $tester = $this->runCommand($this->command(), ['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertSame([[
            'name' => 'wallet-kit',
            'type' => 'open-source',
            'provider' => 'github',
            'project_key' => 'WK',
            'repo_path' => $this->tmp.'/repo',
            'primary_branch' => 'main',
            'worktrees_root' => $this->tmp.'/wt',
        ]], $payload['projects']);
    }
}
