<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Sync;

use Pablo\Command\Sync\RebaseLogCommand;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;
use Pablo\Tests\Command\CommandTestBed;

final class RebaseLogCommandTest extends CommandTestBed
{
    private Sync $sync;

    protected function setUp(): void
    {
        parent::setUp();
        putenv('PABLO_LOGS_DIR='.$this->tmp.'/logs');
        $this->sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->runner, $this->time);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_LOGS_DIR');
        parent::tearDown();
    }

    private function command(): RebaseLogCommand
    {
        return new RebaseLogCommand($this->sync, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
    }

    public function testJson(): void
    {
        $this->sync->saveLastLog('wallet-kit', 'rebase', [
            new SyncReport(worktree: $this->wt, branch: 'wk-45', action: 'conflict', behind: 3, conflictFiles: ['a.php'], agentHandle: 'ses_1'),
        ]);

        $tester = $this->runCommand($this->command(), ['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $log = $payload['logs'][0];
        $this->assertSame('wallet-kit', $log['project']);
        $this->assertSame('rebase', $log['strategy']);
        $this->assertNotNull($log['timestamp']);
        $this->assertSame('wk-45', $log['reports'][0]['branch']);
        $this->assertSame('conflict', $log['reports'][0]['action']);
        $this->assertSame(3, $log['reports'][0]['behind']);
        $this->assertSame(['a.php'], $log['reports'][0]['conflict_files']);
        $this->assertSame('ses_1', $log['reports'][0]['agent_handle']);
    }

    public function testJsonWithoutLogsIsAnEmptyList(): void
    {
        $tester = $this->runCommand($this->command(), ['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame(['logs' => []], json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
    }

    public function testTableWithoutLogs(): void
    {
        $tester = $this->runCommand($this->command());

        $this->assertStringContainsString('no rebase logs found', $tester->getDisplay());
    }
}
