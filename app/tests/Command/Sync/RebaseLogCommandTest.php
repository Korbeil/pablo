<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Sync;

use Pablo\Command\Sync\RebaseLogCommand;
use Pablo\Provider\Git\RebaseLog;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncHistory;
use Pablo\Provider\Git\SyncReport;
use Pablo\Provider\Git\SyncRun;
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

    private function seed(): void
    {
        $history = new SyncHistory();
        $history->append(new SyncRun(new RebaseLog('wallet-kit', '2026-01-01T00:00:00+00:00', 'rebase', [
            new SyncReport(worktree: '/wt/a', branch: 'wk-a', action: 'conflict', conflictFiles: ['src/A.php']),
            new SyncReport(worktree: '/wt/b', branch: 'wk-b', action: 'synced'),
        ]), true));
        $history->append(new SyncRun(new RebaseLog('wallet-kit', '2026-01-02T00:00:00+00:00', 'rebase', [
            new SyncReport(worktree: '/wt/a', branch: 'wk-a', action: 'up-to-date'),
        ]), false));
    }

    public function testHistoryShowsPastRunsNewestFirst(): void
    {
        $this->seed();

        $tester = $this->runCommand($this->command(), ['--history' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $out = $tester->getDisplay();
        $this->assertStringContainsString('# wallet-kit — 2026-01-02T00:00:00+00:00 (rebase, dry run)', $out);
        $this->assertStringContainsString('# wallet-kit — 2026-01-01T00:00:00+00:00 (rebase, applied)', $out);
        $this->assertLessThan(strpos($out, '2026-01-01'), strpos($out, '2026-01-02'));
        $this->assertStringContainsString('conflicting files: src/A.php', $out);
    }

    public function testHistoryLimitAndOnly(): void
    {
        $this->seed();

        $out = $this->runCommand($this->command(), ['--history' => true, '--limit' => '1'])->getDisplay();
        $this->assertStringContainsString('2026-01-02', $out);
        $this->assertStringNotContainsString('2026-01-01', $out);

        $out = $this->runCommand($this->command(), ['--history' => true, '--only' => 'conflict,lease-failed'])->getDisplay();
        $this->assertStringContainsString('2026-01-01', $out);
        $this->assertStringNotContainsString('2026-01-02', $out);
        $this->assertStringNotContainsString('wk-b', $out);
    }

    public function testHistoryOnlyAcceptsEveryReportedAction(): void
    {
        (new SyncHistory())->append(new SyncRun(new RebaseLog('wallet-kit', '2026-01-03T00:00:00+00:00', 'rebase', [
            new SyncReport(worktree: '/wt/c', branch: 'wk-closed', action: 'skipped'),
            new SyncReport(worktree: '/wt/d', branch: 'wk-broken', action: 'error', detail: 'not a git repository'),
            new SyncReport(worktree: '/wt/e', branch: 'wk-ok', action: 'synced'),
        ]), true));

        foreach (array_keys(Sync::ACTION_ICONS) as $action) {
            $this->assertSame(0, $this->runCommand($this->command(), ['--history' => true, '--only' => $action])->getStatusCode(), $action);
        }

        $out = $this->runCommand($this->command(), ['--history' => true, '--only' => 'skipped,error'])->getDisplay();
        $this->assertStringContainsString('wk-closed', $out);
        $this->assertStringContainsString('wk-broken', $out);
        $this->assertStringNotContainsString('wk-ok', $out);
    }

    public function testHistoryRejectsAnUnknownAction(): void
    {
        $tester = $this->runCommand($this->command(), ['--history' => true, '--only' => 'confict']);

        $this->assertSame(1, $tester->getStatusCode());
    }

    public function testEmptyHistory(): void
    {
        $out = $this->runCommand($this->command(), ['--history' => true])->getDisplay();

        $this->assertStringContainsString('no sync history found', $out);
    }

    public function testWithoutHistoryShowsTheLastRunOnly(): void
    {
        $this->seed();
        $this->sync->saveLastLog('wallet-kit', 'rebase', [
            new SyncReport(worktree: '/wt/z', branch: 'wk-last', action: 'synced'),
        ]);

        $out = $this->runCommand($this->command())->getDisplay();

        $this->assertStringContainsString('wk-last', $out);
        $this->assertStringNotContainsString('wk-a', $out);
    }

    public function testHistoryJson(): void
    {
        $this->seed();

        $tester = $this->runCommand($this->command(), ['--history' => true, '--only' => 'conflict', '--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $this->assertCount(1, $payload['runs']);
        $run = $payload['runs'][0];
        $this->assertSame('wallet-kit', $run['project']);
        $this->assertSame('2026-01-01T00:00:00+00:00', $run['timestamp']);
        $this->assertTrue($run['apply']);
        $this->assertSame(['wk-a'], array_column($run['reports'], 'branch'));
        $this->assertSame(['src/A.php'], $run['reports'][0]['conflict_files']);
    }

    public function testHistoryJsonWithoutRunsIsAnEmptyList(): void
    {
        $tester = $this->runCommand($this->command(), ['--history' => true, '--format' => 'json']);

        $this->assertSame(['runs' => []], json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR));
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
