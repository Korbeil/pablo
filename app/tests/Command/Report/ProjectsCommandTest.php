<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Report;

use Pablo\Command\Report\ProjectsCommand;
use Pablo\Tests\Command\CommandTestBed;

final class ProjectsCommandTest extends CommandTestBed
{
    private function command(): ProjectsCommand
    {
        return new ProjectsCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
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
}
