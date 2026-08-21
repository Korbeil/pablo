<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
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

        // Stop syncProject from reaching a real gh CLI for PR-base resolution.
        RepoSlug::setFor(static fn (ProjectConfig $cfg): string => 'octocat/proj');
        GhPr::setPrsForBranches(static fn (string $slug, array $branches): array => []);
    }

    protected function tearDown(): void
    {
        RepoSlug::setFor(null);
        GhPr::setPrsForBranches(null);
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
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: $autoApply,
            syncInterval: 30,
            pollInterval: 10,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
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

    public function testSyncStackedBranchRebasesOntoPrBase(): void
    {
        RepoHelper::commitFile($this->wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($this->wt, ['push', '-q', '-u', 'origin', 'pr-1']);

        // Parent PR branch sits on origin; pr-1 is stacked on it.
        RepoHelper::git($this->other, ['checkout', '-q', '-b', 'pr-parent']);
        RepoHelper::commitFile($this->other, 'parent.txt', "p\n", 'parent work');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'pr-parent']);
        RepoHelper::git($this->other, ['checkout', '-q', 'main']);

        // Advance main independently; a main-based sync would pull this in.
        RepoHelper::commitFile($this->other, 'main-only.txt', "m\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        GhPr::setPrsForBranches(static fn (string $slug, array $branches): array => [
            'pr-1' => new PrInfo(1, 'Child', 'OPEN', false, 'u1', null, 'pr-parent'),
        ]);

        $reports = Sync::syncProject($this->cfg, $this->store, true);
        $report = $this->byBranch($reports)['pr-1'];
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($this->wt.'/feature.txt');
        $this->assertFileExists($this->wt.'/parent.txt');
        $this->assertFileDoesNotExist($this->wt.'/main-only.txt');
    }

    public function testSyncNormalBranchStillRebasesOntoPrimary(): void
    {
        RepoHelper::commitFile($this->wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($this->wt, ['push', '-q', '-u', 'origin', 'pr-1']);

        RepoHelper::commitFile($this->other, 'main-only.txt', "m\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        $reports = Sync::syncProject($this->cfg, $this->store, true);
        $report = $this->byBranch($reports)['pr-1'];
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($this->wt.'/main-only.txt');
    }

    public function testConflictPromptUsesStackedBase(): void
    {
        $report = new \Pablo\Provider\Git\SyncReport(
            worktree: $this->wt,
            branch: 'pr-1',
            action: 'conflict',
            conflictFiles: ['README.md'],
        );
        $prompt = Sync::buildConflictAgentPrompt($report, $this->cfg, 'pr-parent');
        $this->assertStringContainsString('git rebase origin/pr-parent', $prompt);
        $this->assertStringNotContainsString('git rebase origin/main', $prompt);
        $this->assertStringContainsString('stacked', $prompt);
    }

    public function testConflictPromptDefaultsToPrimary(): void
    {
        $report = new \Pablo\Provider\Git\SyncReport(
            worktree: $this->wt,
            branch: 'pr-1',
            action: 'conflict',
            conflictFiles: ['README.md'],
        );
        $prompt = Sync::buildConflictAgentPrompt($report, $this->cfg);
        $this->assertStringContainsString('git rebase origin/main', $prompt);
        $this->assertStringNotContainsString('git rebase origin/pr-parent', $prompt);
    }
}
