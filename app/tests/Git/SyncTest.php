<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase
{
    private string $tmp;
    private string $clone;
    private string $other;
    private Store $store;
    private ProjectConfig $cfg;
    private string $wt;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-sync-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $repos = RepoHelper::makeRepos($this->tmp);
        $this->clone = $repos['clone'];
        $this->other = $repos['other'];

        $this->cfg = $this->makeConfig(false);
        $this->store = new Store($this->tmp.'/state');
        $this->wt = \Pablo\Provider\Git\GitRepo::createWorktree($this->clone, $this->cfg->worktreesRoot, 'pr-1', 'main');
        $this->store->save(new Task('proj', 'pr-1', $this->wt, State::Draft));

        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
    }

    private function makeConfig(bool $autoApply): ProjectConfig
    {
        return new ProjectConfig(
            name: 'proj',
            type: 'personal',
            repoPath: $this->clone,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wtroot',
            provider: 'github',
            identity: 'user',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: $autoApply,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
        );
    }

    /** @param array<int, \Pablo\Provider\Git\SyncReport> $reports
     * @return array<string, \Pablo\Provider\Git\SyncReport>
     */
    private function byBranch(array $reports): array
    {
        $out = [];
        foreach ($reports as $r) {
            $out[$r->branch] = $r;
        }

        return $out;
    }

    public function testSyncRespectsAutoApplyFalseDefault(): void
    {
        $reports = Sync::syncProject($this->cfg, $this->store, null);
        $this->assertSame('would-sync', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileDoesNotExist($this->wt.'/new.txt');
    }

    public function testApplyFlagOverrides(): void
    {
        $reports = Sync::syncProject($this->cfg, $this->store, true);
        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileExists($this->wt.'/new.txt');
    }

    public function testAutoApplyConfigApplies(): void
    {
        $cfg = $this->makeConfig(true);
        $reports = Sync::syncProject($cfg, $this->store, null);
        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
    }

    public function testPrimaryCheckoutNotSynced(): void
    {
        $reports = Sync::syncProject($this->cfg, $this->store, true);
        $branches = array_map(static fn ($r) => $r->branch, $reports);
        $this->assertNotContains('main', $branches);
    }

    public function testLockedTaskSkippedCleanly(): void
    {
        $lock = Store::taskLock($this->store, 'proj', 'pr-1');
        try {
            $reports = Sync::syncProject($this->cfg, $this->store, true);
        } finally {
            $lock->release();
        }
        $this->assertSame('locked', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileDoesNotExist($this->wt.'/new.txt');
    }

    public function testUntrackedWorktreeIsSkipped(): void
    {
        $wt2 = \Pablo\Provider\Git\GitRepo::createWorktree($this->clone, $this->cfg->worktreesRoot, 'pr-x', 'main');
        RepoHelper::commitFile($this->other, 'new2.txt', "y\n", 'advance main again');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $reports = Sync::syncProject($this->cfg, $this->store, true);

        // A worktree not backed by an active task is never synced or reported.
        $branches = array_map(static fn ($r) => $r->branch, $reports);
        $this->assertNotContains('pr-x', $branches);
        $this->assertFileDoesNotExist($wt2.'/new2.txt');
    }

    public function testReportsRenderConflicts(): void
    {
        RepoHelper::commitFile($this->wt, 'README.md', "local\n", 'local edit');
        RepoHelper::commitFile($this->other, 'README.md', "remote\n", 'remote edit');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $reports = Sync::syncProject($this->cfg, $this->store, true);
        $text = Sync::renderReports($reports);
        $this->assertStringContainsString('conflict', $text);
        $this->assertStringContainsString('README.md', $text);
    }
}
