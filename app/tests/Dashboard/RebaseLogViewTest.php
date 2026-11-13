<?php

declare(strict_types=1);

namespace Pablo\Tests\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Dashboard\RebaseLogView;
use Pablo\Provider\Git\RebaseLog;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncHistory;
use Pablo\Provider\Git\SyncReport;
use Pablo\Provider\Git\SyncRun;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class RebaseLogViewTest extends TestCase
{
    private function sync(): Sync
    {
        $slugGit = new \Pablo\Tests\FakeGit();
        $slugGit->originUrl = 'git@github.com:octocat/proj.git';

        return new Sync(
            new \Pablo\Provider\Git\GitRepo(),
            new \Pablo\Tests\FakeGhPr(),
            new \Pablo\Support\RepoSlug($slugGit),
            new \Pablo\Domain\Time(),
        );
    }
    private string $logs;
    private RebaseLogView $view;

    protected function setUp(): void
    {
        $this->logs = TempDir::create('logs');
        putenv('PABLO_LOGS_DIR='.$this->logs);
        $this->view = new RebaseLogView($this->sync());
    }

    protected function tearDown(): void
    {
        putenv('PABLO_LOGS_DIR');
        array_map('unlink', glob($this->logs.'/*') ?: []);
        @rmdir($this->logs);
    }

    private function cfg(string $name): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repo: new RepoConfig(
                path: '/tmp/'.$name,
                primaryBranch: 'main',
                worktreesRoot: '/tmp/wt',
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 720,
            ),
            statePolling: new StatePollingConfig(10),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    public function testMissingLogIsNull(): void
    {
        $this->assertNull($this->view->forProject('nope'));
    }

    public function testDecodesASavedSession(): void
    {
        $this->sync()->saveLastLog('web', 'rebase', [
            new SyncReport(worktree: '/wt/a', branch: 'a', action: 'synced', behind: 3, ahead: 1),
            new SyncReport(
                worktree: '/wt/b',
                branch: 'b',
                action: 'conflict',
                conflictFiles: ['src/A.php', 'src/B.php'],
                agentHandle: 'ses_123',
            ),
        ]);

        $section = $this->view->forProject('web');

        $this->assertNotNull($section);
        $log = $section->log;
        $this->assertSame('web', $log->project);
        $this->assertSame('rebase', $log->strategy);
        $this->assertNotNull($log->timestamp);

        $r0 = $section->rows[0];
        $this->assertSame('synced', $r0->report->action);
        $this->assertSame('is-success', $r0->color);
        $this->assertSame('lucide:refresh-cw', $r0->icon);
        $this->assertSame('💚', $r0->emoji);
        $this->assertSame(3, $r0->report->behind);
        $this->assertSame(1, $r0->report->ahead);

        $r1 = $section->rows[1];
        $this->assertSame('is-danger', $r1->color);
        $this->assertSame(['src/A.php', 'src/B.php'], $r1->report->conflictFiles);
        $this->assertSame('ses_123', $r1->report->agentHandle);
    }

    /**
     * Every action Sync can emit must have a dashboard style. Guards against a
     * new SyncReport action silently rendering as a grey "unknown" chip.
     */
    public function testEveryKnownActionHasAStyle(): void
    {
        $this->assertSame(
            [],
            array_diff(array_keys(Sync::ACTION_ICONS), array_keys(RebaseLogView::ACTION_STYLE)),
            'SyncReport actions with no entry in RebaseLogView::ACTION_STYLE',
        );
    }

    public function testKnownActionsKeepTheirTerminalEmoji(): void
    {
        $reports = [];
        foreach (array_keys(Sync::ACTION_ICONS) as $action) {
            $reports[] = new SyncReport(worktree: '/wt', branch: $action, action: $action);
        }
        $this->sync()->saveLastLog('web', 'merge', $reports);

        $section = $this->view->forProject('web');

        $this->assertNotNull($section);
        foreach ($section->rows as $row) {
            $this->assertSame(Sync::ACTION_ICONS[$row->report->action], $row->emoji);
        }
    }

    public function testUnknownActionDegradesGracefully(): void
    {
        $this->sync()->saveLastLog('web', 'rebase', [
            new SyncReport(worktree: '/wt', branch: 'x', action: 'from-the-future'),
        ]);

        $section = $this->view->forProject('web');

        $this->assertNotNull($section);
        $this->assertSame('pablo-chip-muted', $section->rows[0]->color);
        $this->assertSame('•', $section->rows[0]->emoji);
    }

    public function testShowsEarlierNotableEventsFromTheHistory(): void
    {
        $history = new SyncHistory();
        $history->append(new SyncRun(new RebaseLog('web', '2026-01-01T00:00:00+00:00', 'rebase', [
            new SyncReport(worktree: '/wt/a', branch: 'a', action: 'conflict', conflictFiles: ['src/A.php']),
        ]), true));
        $history->append(new SyncRun(new RebaseLog('web', '2026-01-02T00:00:00+00:00', 'rebase', [
            new SyncReport(worktree: '/wt/b', branch: 'b', action: 'dirty'),
            new SyncReport(worktree: '/wt/c', branch: 'c', action: 'lease-failed'),
            new SyncReport(worktree: '/wt/r', branch: 'r', action: 'local-rewrite'),
            new SyncReport(worktree: '/wt/e', branch: 'e', action: 'error', detail: 'not a git repository'),
            new SyncReport(worktree: '/wt/f', branch: 'f', action: 'skipped'),
        ]), false));
        // the last run: shown as the card's rows, not repeated as an event
        $last = $this->sync()->saveLastLog('web', 'rebase', [
            new SyncReport(worktree: '/wt/d', branch: 'd', action: 'conflict'),
        ]);
        $history->append(new SyncRun($last, true));

        $section = $this->view->forProject('web');

        $this->assertNotNull($section);
        $this->assertSame(['c', 'r', 'e', 'a'], array_map(static fn ($e): string => $e->row->report->branch, $section->events));
        $this->assertSame('2026-01-02T00:00:00+00:00', $section->events[0]->timestamp);
        $this->assertFalse($section->events[0]->apply);
        $this->assertSame('is-warning', $section->events[0]->row->color);
        $this->assertSame('is-warning', $section->events[1]->row->color);
        $this->assertSame('is-danger', $section->events[2]->row->color);
        $this->assertSame(['src/A.php'], $section->events[3]->row->report->conflictFiles);
    }

    public function testEarlierEventsAreCapped(): void
    {
        $history = new SyncHistory();
        for ($i = 1; $i <= RebaseLogView::EVENTS_SHOWN + 3; ++$i) {
            $history->append(new SyncRun(new RebaseLog('web', \sprintf('2026-01-%02dT00:00:00+00:00', $i), 'rebase', [
                new SyncReport(worktree: '/wt', branch: "b{$i}", action: 'conflict'),
            ]), true));
        }
        $this->sync()->saveLastLog('web', 'rebase', []);

        $section = $this->view->forProject('web');

        $this->assertNotNull($section);
        $this->assertCount(RebaseLogView::EVENTS_SHOWN, $section->events);
        $this->assertSame('b8', $section->events[0]->row->report->branch);
    }

    public function testForProjectsSkipsMissingAndSortsNewestFirst(): void
    {
        $this->sync()->saveLastLog('older', 'rebase', []);
        // saveLastLog stamps utcnow(); force a distinct, older timestamp
        $path = $this->logs.'/rebase-last-older.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['timestamp'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($path, json_encode($data));

        $this->sync()->saveLastLog('newer', 'rebase', []);

        $logs = $this->view->forProjects([
            'older' => $this->cfg('older'),
            'never-synced' => $this->cfg('never-synced'),
            'newer' => $this->cfg('newer'),
        ]);

        $this->assertSame(['newer', 'older'], array_map(static fn ($s): string => $s->project(), $logs));
    }
}
