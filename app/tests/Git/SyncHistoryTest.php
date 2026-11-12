<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Provider\Git\RebaseLog;
use Pablo\Provider\Git\SyncHistory;
use Pablo\Provider\Git\SyncReport;
use Pablo\Provider\Git\SyncRun;
use PHPUnit\Framework\TestCase;

final class SyncHistoryTest extends TestCase
{
    private string $logs;

    protected function setUp(): void
    {
        $this->logs = sys_get_temp_dir().'/pablo-history-'.uniqid();
        putenv('PABLO_LOGS_DIR='.$this->logs);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_LOGS_DIR');
        exec('rm -rf '.escapeshellarg($this->logs));
    }

    /** @param list<string> $actions */
    private function syncRun(string $ts, array $actions, bool $apply = true, string $project = 'web'): SyncRun
    {
        $reports = [];
        foreach ($actions as $i => $action) {
            $reports[] = new SyncReport(
                worktree: '/wt/'.$i,
                branch: "b{$i}",
                action: $action,
                conflictFiles: 'conflict' === $action ? ['src/A.php'] : [],
                detail: 'lease-failed' === $action ? 'remote moved' : '',
            );
        }

        return new SyncRun(new RebaseLog($project, $ts, 'rebase', $reports), $apply);
    }

    public function testAppendsOneLinePerRunAndReadsNewestFirst(): void
    {
        $history = new SyncHistory();
        $history->append($this->syncRun('2026-01-01T00:00:00+00:00', ['synced'], false));
        $history->append($this->syncRun('2026-01-02T00:00:00+00:00', ['conflict', 'up-to-date']));

        $this->assertCount(2, file(SyncHistory::path('web'), \FILE_SKIP_EMPTY_LINES) ?: []);
        $runs = $history->read('web');
        $this->assertSame(['2026-01-02T00:00:00+00:00', '2026-01-01T00:00:00+00:00'], array_map(static fn (SyncRun $r) => $r->log->timestamp, $runs));
        $this->assertTrue($runs[0]->apply);
        $this->assertFalse($runs[1]->apply);
        $this->assertSame('rebase', $runs[0]->log->strategy);
        $this->assertSame(['src/A.php'], $runs[0]->log->reports[0]->conflictFiles);
        $this->assertSame([], $history->read('other'));
    }

    public function testIsCappedToTheLastRuns(): void
    {
        $history = new SyncHistory(3);
        for ($i = 1; $i <= 5; ++$i) {
            $history->append($this->syncRun("2026-01-0{$i}T00:00:00+00:00", ['synced']));
        }

        $this->assertCount(3, file(SyncHistory::path('web'), \FILE_SKIP_EMPTY_LINES) ?: []);
        $this->assertSame(
            ['2026-01-05T00:00:00+00:00', '2026-01-04T00:00:00+00:00', '2026-01-03T00:00:00+00:00'],
            array_map(static fn (SyncRun $r) => $r->log->timestamp, $history->read('web')),
        );
    }

    public function testLimitAndActionFilter(): void
    {
        $history = new SyncHistory();
        $history->append($this->syncRun('2026-01-01T00:00:00+00:00', ['conflict']));
        $history->append($this->syncRun('2026-01-02T00:00:00+00:00', ['synced', 'dirty']));
        $history->append($this->syncRun('2026-01-03T00:00:00+00:00', ['up-to-date', 'lease-failed']));
        $history->append($this->syncRun('2026-01-04T00:00:00+00:00', ['up-to-date']));

        $this->assertCount(2, $history->read('web', 2));

        $runs = $history->read('web', null, ['conflict', 'lease-failed']);
        $this->assertSame(['2026-01-03T00:00:00+00:00', '2026-01-01T00:00:00+00:00'], array_map(static fn (SyncRun $r) => $r->log->timestamp, $runs));
        // only the matching reports are kept
        $this->assertSame(['lease-failed'], array_map(static fn (SyncReport $r) => $r->action, $runs[0]->log->reports));
        $this->assertSame('remote moved', $runs[0]->log->reports[0]->detail);

        $this->assertCount(1, $history->read('web', 1, ['conflict', 'lease-failed']));
    }

    public function testCorruptLinesAreSkipped(): void
    {
        $history = new SyncHistory();
        $history->append($this->syncRun('2026-01-01T00:00:00+00:00', ['synced']));
        file_put_contents(SyncHistory::path('web'), "{not json\n[1,2]\n{\"reports\":[\"x\"]}\n", \FILE_APPEND);
        $history->append($this->syncRun('2026-01-02T00:00:00+00:00', ['conflict']));

        $this->assertSame(
            ['2026-01-02T00:00:00+00:00', '2026-01-01T00:00:00+00:00'],
            array_map(static fn (SyncRun $r) => $r->log->timestamp, $history->read('web')),
        );
    }

    public function testAWriteFailureNeverThrows(): void
    {
        // the logs "directory" is a file: nothing can be written under it
        file_put_contents($this->logs, 'x');

        (new SyncHistory())->append($this->syncRun('2026-01-01T00:00:00+00:00', ['synced']));

        $this->assertSame([], (new SyncHistory())->read('web'));
        unlink($this->logs);
    }
}
