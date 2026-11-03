<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Gh\PrInfo;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
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

        return new Sync(
            new \Pablo\Provider\Git\GitRepo(),
            $this->gh,
            new RepoSlug($slugGit),
            new \Pablo\Support\ProcessRunner(),
            new \Pablo\Domain\Time(),
        );
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
        $this->tmp = sys_get_temp_dir().'/pablo-sync-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $repos = RepoHelper::makeRepos($this->tmp);
        $this->clone = $repos['clone'];
        $this->other = $repos['other'];

        $this->cfg = $this->makeConfig(false);
        $this->store = new Store($this->tmp.'/state');
        $this->wt = $this->realGit()->createWorktree($this->clone, $this->cfg->worktreesRoot, 'pr-1', 'main');
        $this->store->save(new Task('proj', 'pr-1', $this->wt, State::Draft));

        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        // Stop syncProject from reaching a real gh CLI for PR-base resolution.
    }

    protected function tearDown(): void
    {
    }

    private function makeConfig(bool $autoApply, string $provider = 'github'): ProjectConfig
    {
        return new ProjectConfig(
            name: 'proj',
            type: 'personal',
            repoPath: $this->clone,
            primaryBranch: 'main',
            worktreesRoot: $this->tmp.'/wtroot',
            provider: $provider,
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

    public function testUntrackedWorktreeIsSkipped(): void
    {
        $wt2 = $this->realGit()->createWorktree($this->clone, $this->cfg->worktreesRoot, 'pr-x', 'main');
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
        $this->assertTrue(\Pablo\Agents\Runtime\SessionRef::isClaudeSession($session));
        $this->assertSame($session, $report->agentHandle);
        $this->assertStringContainsString('fix agent running — claude --resume '.$session, Sync::renderReports([$report]));
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
        $this->assertTrue(\Pablo\Agents\Runtime\SessionRef::isClaudeSession($session), 'a bare UUID goes to the CLI');
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
    }
}
