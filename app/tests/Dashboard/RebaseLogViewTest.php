<?php

declare(strict_types=1);

namespace Pablo\Tests\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Dashboard\RebaseLogView;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;
use PHPUnit\Framework\TestCase;

final class RebaseLogViewTest extends TestCase
{
    private string $logs;
    private RebaseLogView $view;

    protected function setUp(): void
    {
        $this->logs = sys_get_temp_dir().'/pablo-logs-'.uniqid();
        mkdir($this->logs, 0o777, true);
        putenv('PABLO_LOGS_DIR='.$this->logs);
        $this->view = new RebaseLogView();
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
            repoPath: '/tmp/'.$name,
            primaryBranch: 'main',
            worktreesRoot: '/tmp/wt',
            provider: 'github',
            identity: 'korbeil',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 720,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
        );
    }

    public function testMissingLogIsNull(): void
    {
        $this->assertNull($this->view->forProject('nope'));
    }

    public function testDecodesASavedSession(): void
    {
        Sync::saveLastLog('web', 'rebase', [
            new SyncReport(worktree: '/wt/a', branch: 'a', action: 'synced', behind: 3, ahead: 1),
            new SyncReport(
                worktree: '/wt/b',
                branch: 'b',
                action: 'conflict',
                conflictFiles: ['src/A.php', 'src/B.php'],
                agentHandle: 'ses_123',
            ),
        ]);

        $log = $this->view->forProject('web');

        $this->assertNotNull($log);
        $this->assertSame('web', $log['project']);
        $this->assertSame('rebase', $log['strategy']);
        $this->assertNotNull($log['timestamp']);

        $this->assertSame('synced', $log['reports'][0]['action']);
        $this->assertSame('is-success', $log['reports'][0]['color']);
        $this->assertSame('lucide:refresh-cw', $log['reports'][0]['icon']);
        $this->assertSame('💚', $log['reports'][0]['emoji']);
        $this->assertSame(3, $log['reports'][0]['behind']);
        $this->assertSame(1, $log['reports'][0]['ahead']);

        $this->assertSame('is-danger', $log['reports'][1]['color']);
        $this->assertSame(['src/A.php', 'src/B.php'], $log['reports'][1]['conflict_files']);
        $this->assertSame('ses_123', $log['reports'][1]['agent_handle']);
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
        Sync::saveLastLog('web', 'merge', $reports);

        $log = $this->view->forProject('web');

        $this->assertNotNull($log);
        foreach ($log['reports'] as $report) {
            $this->assertSame(Sync::ACTION_ICONS[$report['action']], $report['emoji']);
        }
    }

    public function testUnknownActionDegradesGracefully(): void
    {
        Sync::saveLastLog('web', 'rebase', [
            new SyncReport(worktree: '/wt', branch: 'x', action: 'from-the-future'),
        ]);

        $log = $this->view->forProject('web');

        $this->assertNotNull($log);
        $this->assertSame('pablo-chip-muted', $log['reports'][0]['color']);
        $this->assertSame('•', $log['reports'][0]['emoji']);
    }

    public function testForProjectsSkipsMissingAndSortsNewestFirst(): void
    {
        Sync::saveLastLog('older', 'rebase', []);
        // saveLastLog stamps utcnow(); force a distinct, older timestamp
        $path = $this->logs.'/rebase-last-older.json';
        $data = json_decode((string) file_get_contents($path), true);
        $data['timestamp'] = '2020-01-01T00:00:00+00:00';
        file_put_contents($path, json_encode($data));

        Sync::saveLastLog('newer', 'rebase', []);

        $logs = $this->view->forProjects([
            'older' => $this->cfg('older'),
            'never-synced' => $this->cfg('never-synced'),
            'newer' => $this->cfg('newer'),
        ]);

        $this->assertSame(['newer', 'older'], array_column($logs, 'project'));
    }
}
