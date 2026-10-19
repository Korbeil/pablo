<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Sync;

use Pablo\Command\Sync\MainUpdateCommand;
use Pablo\Provider\Git\MainUpdateReport;
use Pablo\Tests\Command\CommandTestBed;

final class MainUpdateCommandTest extends CommandTestBed
{
    private function command(): MainUpdateCommand
    {
        return new MainUpdateCommand($this->git, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
    }

    public function testUpdatesEveryProject(): void
    {
        $this->git->mainUpdateReport = new MainUpdateReport($this->tmp.'/repo', 'main', 'updated', 'main', 'aaaaaaaaaa', 'bbbbbbbbbb');

        $tester = $this->runCommand($this->command());

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([[$this->tmp.'/repo', 'main', 'origin']], $this->git->primaryUpdates);
        $this->assertStringContainsString('[wallet-kit] main: ✅ updated main aaaaaaa → bbbbbbb', $tester->getDisplay());
    }

    public function testUnknownProjectFails(): void
    {
        $tester = $this->runCommand($this->command(), ['project' => 'nope']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame([], $this->git->primaryUpdates);
    }
}
