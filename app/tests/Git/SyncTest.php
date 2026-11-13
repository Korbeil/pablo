<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class SyncTest extends TestCase
{
    private \Pablo\Tests\FakeGhPr $gh;

    private function sync(): Sync
    {
        if (!isset($this->gh)) {
            $this->gh = new \Pablo\Tests\FakeGhPr();
        }

        $slugGit = new \Pablo\Tests\FakeGit();
        $slugGit->originUrl = 'git@github.com:octocat/proj.git';

        return new Sync(new \Pablo\Provider\Git\GitRepo(), $this->gh, new RepoSlug($slugGit), new \Pablo\Domain\Time(), \Pablo\Tests\Registries::runtimes(), // Short lock timeout: testLockedTaskSkippedCleanly waits it out.
            lockTimeoutS: 0.2);
    }

    private function realGit(): \Pablo\Provider\Git\GitRepoInterface
    {
        return new \Pablo\Provider\Git\GitRepo();
    }
    private string $tmp;
    private string $clone;
    private string $other;
    private Store $store;
    private ProjectConfig $cfg;
    private string $wt;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('sync');
        // syncProject writes rebase-last-*.json and the sync history: keep
        // them out of the developer's ~/.pablo/logs.
        putenv('PABLO_LOGS_DIR='.$this->tmp.'/logs');
        // The task worktree pr-1 off main, then main advanced on origin.
        $repos = RepoHelper::makeReposThen('sync', $this->tmp, static function (string $dir, array $repos): void {
            (new \Pablo\Provider\Git\GitRepo())->createWorktree($repos['clone'], $dir.'/wtroot', 'pr-1', 'main');
            RepoHelper::commitFile($repos['other'], 'new.txt', "x\n", 'advance main');
            RepoHelper::git($repos['other'], ['push', '-q', 'origin', 'main']);
        });
        $this->clone = $repos['clone'];
        $this->other = $repos['other'];

        $this->cfg = $this->makeConfig(false);
        $this->store = new Store($this->tmp.'/state');
        $this->wt = $this->cfg->repo->worktreesRoot.'/pr-1';
        $this->store->save(new Task('proj', 'pr-1', $this->wt, State::Draft));
    }

    protected function tearDown(): void
    {
        putenv('PABLO_LOGS_DIR');
    }

    private function makeConfig(bool $autoApply, string $provider = 'github'): ProjectConfig
    {
        return new ProjectConfig(
            name: 'proj',
            type: 'personal',
            repo: new RepoConfig(
                path: $this->clone,
                primaryBranch: 'main',
                worktreesRoot: $this->tmp.'/wtroot',
            ),
            tracker: new TrackerConfig(
                provider: $provider,
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: $autoApply,
                intervalMinutes: 30,
            ),
            statePolling: new StatePollingConfig(10),
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
        $reports = $this->sync()->syncProject($this->cfg, $this->store, null);
        $this->assertSame('would-sync', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileDoesNotExist($this->wt.'/new.txt');
    }

    public function testApplyFlagOverrides(): void
    {
        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileExists($this->wt.'/new.txt');
    }

    public function testAutoApplyConfigApplies(): void
    {
        $cfg = $this->makeConfig(true);
        $reports = $this->sync()->syncProject($cfg, $this->store, null);
        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
    }

    public function testEachRunIsAppendedToTheHistory(): void
    {
        $this->sync()->syncProject($this->cfg, $this->store, null);
        $this->sync()->syncProject($this->cfg, $this->store, true);

        $runs = $this->sync()->history('proj');
        $this->assertCount(2, $runs);
        // newest first: the applied run, then the dry run
        $this->assertTrue($runs[0]->apply);
        $this->assertSame('synced', $runs[0]->log->reports[0]->action);
        $this->assertFalse($runs[1]->apply);
        $this->assertSame('would-sync', $runs[1]->log->reports[0]->action);
        // the last-run file is still written, unchanged in shape
        $last = $this->sync()->loadLastLog('proj');
        $this->assertNotNull($last);
        $this->assertSame('synced', $last->reports[0]->action);
        $this->assertArrayNotHasKey('apply', (array) json_decode((string) file_get_contents($this->tmp.'/logs/rebase-last-proj.json'), true));
    }

    public function testPrimaryCheckoutNotSynced(): void
    {
        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        $branches = array_map(static fn ($r) => $r->branch, $reports);
        $this->assertNotContains('main', $branches);
    }

    public function testLockedTaskSkippedCleanly(): void
    {
        $lock = $this->store->taskLock('proj', 'pr-1');
        try {
            $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        } finally {
            $lock->release();
        }
        $this->assertSame('locked', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileDoesNotExist($this->wt.'/new.txt');
    }

    public function testOneBrokenWorktreeDoesNotStopTheOthers(): void
    {
        // A task worktree deleted by hand stays listed by git, so every git
        // call in it fails; the other tasks must still sync and be logged.
        $broken = $this->realGit()->createWorktree($this->clone, $this->cfg->repo->worktreesRoot, 'pr-2', 'main');
        $this->store->save(new Task('proj', 'pr-2', $broken, State::Draft));
        (new \Symfony\Component\Filesystem\Filesystem())->remove($broken);

        $reports = $this->byBranch($this->sync()->syncProject($this->cfg, $this->store, true));

        $this->assertSame('error', $reports['pr-2']->action);
        $this->assertStringContainsString('will retry next cycle', $reports['pr-2']->detail);
        $this->assertSame('synced', $reports['pr-1']->action);
        $this->assertFileExists($this->wt.'/new.txt');

        $log = $this->sync()->loadLastLog('proj');
        $this->assertNotNull($log);
        $actions = array_map(static fn ($r) => $r->action, $log->reports);
        $this->assertContains('error', $actions);
        $this->assertContains('synced', $actions);
        $this->assertStringContainsString('pr-2', Sync::renderReports($log->reports));
    }

    public function testClosedTaskIsNotSynced(): void
    {
        $this->store->save(new Task('proj', 'pr-1', $this->wt, State::Closed));

        $reports = $this->sync()->syncProject($this->makeConfig(true), $this->store, null);

        $report = $this->byBranch($reports)['pr-1'];
        $this->assertSame('skipped', $report->action);
        $this->assertStringContainsString('closed', $report->detail);
        $this->assertFileDoesNotExist($this->wt.'/new.txt');
    }

    public function testWaitingTaskIsStillSynced(): void
    {
        // Pausing a task never pauses its worktree sync.
        $this->store->save(new Task('proj', 'pr-1', $this->wt, State::Waiting));

        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);

        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
    }

    public function testUntrackedWorktreeIsSkipped(): void
    {
        $wt2 = $this->realGit()->createWorktree($this->clone, $this->cfg->repo->worktreesRoot, 'pr-x', 'main');
        RepoHelper::commitFile($this->other, 'new2.txt', "y\n", 'advance main again');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);

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
        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        $text = Sync::renderReports($reports);
        $this->assertStringContainsString('conflict', $text);
        $this->assertStringContainsString('README.md', $text);
    }

    /** pr-1 (pushed, with a feature commit) stacked on pr-parent; main advanced on its own. */
    private function stackOnParent(string $prState = 'OPEN', string $base = 'pr-parent'): void
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

        $this->gh = new \Pablo\Tests\FakeGhPr();
        $this->gh->prByBranch = [
            'pr-1' => new PrInfo(1, 'Child', $prState, false, 'u1', null, $base),
        ];
    }

    public function testSyncStackedBranchRebasesOntoPrBase(): void
    {
        $this->stackOnParent();

        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        $report = $this->byBranch($reports)['pr-1'];
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($this->wt.'/feature.txt');
        $this->assertFileExists($this->wt.'/parent.txt');
        $this->assertFileDoesNotExist($this->wt.'/main-only.txt');
    }

    public function testSyncStackedBranchHonoursPrBaseForNonGithubTracker(): void
    {
        // A Jira-tracked repo still has its PRs on GitHub: the PR base wins.
        $this->stackOnParent();

        $reports = $this->sync()->syncProject($this->makeConfig(false, 'jira'), $this->store, true);
        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileExists($this->wt.'/parent.txt');
        $this->assertFileDoesNotExist($this->wt.'/main-only.txt');
    }

    public function testSyncIgnoresBaseOfClosedPr(): void
    {
        $this->stackOnParent('CLOSED');

        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        $this->assertSame('synced', $this->byBranch($reports)['pr-1']->action);
        $this->assertFileExists($this->wt.'/main-only.txt');
        $this->assertFileDoesNotExist($this->wt.'/parent.txt');
    }

    public function testStackedConflictReportCarriesPrBaseTarget(): void
    {
        $this->stackOnParent();
        RepoHelper::git($this->other, ['checkout', '-q', 'pr-parent']);
        RepoHelper::commitFile($this->other, 'feature.txt', "parent side\n", 'clash');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'pr-parent']);
        RepoHelper::git($this->other, ['checkout', '-q', 'main']);

        $report = $this->byBranch($this->sync()->syncProject($this->cfg, $this->store, true))['pr-1'];
        $this->assertSame('conflict', $report->action);
        $this->assertSame('origin/pr-parent', $report->target);
        $prompt = $this->sync()->buildConflictAgentPrompt($report, $this->cfg);
        $this->assertStringContainsString('git rebase origin/pr-parent', $prompt);
    }

    public function testConflictOnMissingStackedBaseTargetsPrimary(): void
    {
        // The PR still names a base that is gone from the remote (e.g. deleted
        // after the parent merged): the engine falls back to main, and the
        // resolver must be pointed at main too, not at the missing ref.
        $this->stackOnParent('OPEN', 'pr-gone');
        RepoHelper::commitFile($this->other, 'feature.txt', "main side\n", 'clash');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        $report = $this->byBranch($this->sync()->syncProject($this->cfg, $this->store, true))['pr-1'];
        $this->assertSame('conflict', $report->action);
        $this->assertSame('origin/main', $report->target);
        $prompt = $this->sync()->buildConflictAgentPrompt($report, $this->cfg);
        $this->assertStringContainsString('git rebase origin/main', $prompt);
        $this->assertStringNotContainsString('pr-gone', $prompt);
        $this->assertStringNotContainsString('stacked', $prompt);
    }

    public function testSyncNormalBranchStillRebasesOntoPrimary(): void
    {
        RepoHelper::commitFile($this->wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($this->wt, ['push', '-q', '-u', 'origin', 'pr-1']);

        RepoHelper::commitFile($this->other, 'main-only.txt', "m\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        $reports = $this->sync()->syncProject($this->cfg, $this->store, true);
        $report = $this->byBranch($reports)['pr-1'];
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($this->wt.'/main-only.txt');
    }

    public function testClaudeCodeResolverGetsAPabloAssignedSession(): void
    {
        RepoHelper::commitFile($this->wt, 'feature.txt', "branch side\n", 'branch work');
        RepoHelper::commitFile($this->other, 'feature.txt', "main side\n", 'clash');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $agents = new \Pablo\Tests\FakeAgents();

        putenv('PABLO_AGENT_RUNTIME=claude-code');
        try {
            $report = $this->byBranch($this->sync()->syncProject($this->cfg, $this->store, true, $agents))['pr-1'];
        } finally {
            putenv('PABLO_AGENT_RUNTIME');
        }

        $this->assertSame('conflict', $report->action);
        $this->assertCount(1, $agents->headless);
        $this->assertSame('rebase-conflict-resolver', $agents->headless[0]['agent']);
        $session = (string) $agents->headless[0]['session'];
        $this->assertTrue((new \Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions())->owns($session));
        $this->assertSame($session, $report->agentHandle);
        $this->assertStringContainsString('fix agent running — claude --resume '.$session, Sync::renderReports([$report]));
    }

    public function testOpenCodeResolverGetsAPabloAssignedSession(): void
    {
        RepoHelper::commitFile($this->wt, 'feature.txt', "branch side\n", 'branch work');
        RepoHelper::commitFile($this->other, 'feature.txt', "main side\n", 'clash');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $agents = new \Pablo\Tests\FakeAgents();

        putenv('PABLO_AGENT_RUNTIME=opencode');
        try {
            $report = $this->byBranch($this->sync()->syncProject($this->cfg, $this->store, true, $agents))['pr-1'];
        } finally {
            putenv('PABLO_AGENT_RUNTIME');
        }

        // Known up front: no lookup of the worktree's newest session afterwards.
        $session = (string) $agents->headless[0]['session'];
        $this->assertStringStartsWith('ses_pablo', $session);
        $this->assertSame($session, $report->agentHandle);
        $this->assertStringContainsString('fix agent running — opencode -s '.$session, Sync::renderReports([$report]));
    }

    public function testCopilotResolverHandleIsPrefixed(): void
    {
        RepoHelper::commitFile($this->wt, 'feature.txt', "branch side\n", 'branch work');
        RepoHelper::commitFile($this->other, 'feature.txt', "main side\n", 'clash');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $agents = new \Pablo\Tests\FakeAgents();

        putenv('PABLO_AGENT_RUNTIME=copilot');
        try {
            $report = $this->byBranch($this->sync()->syncProject($this->cfg, $this->store, true, $agents))['pr-1'];
        } finally {
            putenv('PABLO_AGENT_RUNTIME');
        }

        $session = (string) $agents->headless[0]['session'];
        $this->assertTrue((new \Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions())->owns($session), 'a bare UUID goes to the CLI');
        $this->assertSame('copilot:'.$session, $report->agentHandle);
        $this->assertStringContainsString('fix agent running — copilot --resume='.$session, Sync::renderReports([$report]));
    }

    public function testConflictPromptUsesStackedBase(): void
    {
        $report = new \Pablo\Provider\Git\SyncReport(
            worktree: $this->wt,
            branch: 'pr-1',
            action: 'conflict',
            conflictFiles: ['README.md'],
            target: 'origin/pr-parent',
        );
        $prompt = $this->sync()->buildConflictAgentPrompt($report, $this->cfg);
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
        $prompt = $this->sync()->buildConflictAgentPrompt($report, $this->cfg);
        $this->assertStringContainsString('git rebase origin/main', $prompt);
        $this->assertStringNotContainsString('git rebase origin/pr-parent', $prompt);
        $this->assertStringContainsString('If `origin/pr-1` has new commits', $prompt);
        $this->assertStringContainsString('git push --force-with-lease origin pr-1', $prompt);
    }

    public function testConflictPromptUsesTheBranchPushRemote(): void
    {
        $report = new \Pablo\Provider\Git\SyncReport(
            worktree: $this->wt,
            branch: 'pr-1',
            action: 'conflict',
            conflictFiles: ['README.md'],
            pushRemote: 'mine',
        );
        $prompt = $this->sync()->buildConflictAgentPrompt($report, $this->cfg);
        $this->assertStringContainsString('git fetch --prune origin mine', $prompt);
        $this->assertStringContainsString('If `mine/pr-1` has new commits', $prompt);
        $this->assertStringContainsString('git push --force-with-lease mine pr-1', $prompt);
    }
}
