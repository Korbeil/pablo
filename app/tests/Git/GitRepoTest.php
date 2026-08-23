<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Provider\Git\GitRepo;
use PHPUnit\Framework\TestCase;

final class GitRepoTest extends TestCase
{
    private \Pablo\Provider\Git\GitRepoInterface $gitRepo;

    private function git(): \Pablo\Provider\Git\GitRepoInterface
    {
        return $this->gitRepo ??= new GitRepo();
    }
    private string $tmp;
    private string $clone;
    private string $other;
    private string $root;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-git-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $repos = RepoHelper::makeRepos($this->tmp);
        $this->clone = $repos['clone'];
        $this->other = $repos['other'];
        $this->root = $this->tmp.'/worktrees';
    }

    private function makeWorktree(string $branch = 'wk-1'): string
    {
        return $this->git()->createWorktree($this->clone, $this->root, $branch, 'main');
    }

    public function testCreateWorktreeBranchesOffPrimary(): void
    {
        $wt = $this->makeWorktree();
        $this->assertDirectoryExists($wt);
        $this->assertSame('wk-1', RepoHelper::git($wt, ['branch', '--show-current']));
        $this->assertSame(
            RepoHelper::git($this->clone, ['rev-parse', 'main']),
            RepoHelper::git($wt, ['rev-parse', 'HEAD']),
        );
    }

    public function testListWorktrees(): void
    {
        $wt = $this->makeWorktree();
        $entries = $this->git()->listWorktrees($this->clone);
        $found = false;
        foreach ($entries as $ref) {
            if (realpath($ref->path) === realpath($wt) && 'wk-1' === $ref->branch) {
                $found = true;
            }
        }
        $this->assertTrue($found);
    }

    public function testRemoveWorktreeDeletesSlashPrefixedBranch(): void
    {
        $wt = $this->git()->createWorktree($this->clone, $this->root, 'octocat/wk-9', 'main');
        $this->assertDirectoryExists($wt);
        $this->assertSame('octocat/wk-9', RepoHelper::git($wt, ['branch', '--show-current']));

        // Task tracks the short name ("wk-9") but the real branch is prefixed.
        $this->git()->removeWorktree($this->clone, $wt, 'wk-9');

        $this->assertDirectoryDoesNotExist($wt);
        $names = $this->git()->allBranchNames($this->clone);
        $this->assertNotContains('octocat/wk-9', $names);
        $this->assertNotContains('wk-9', $names);
    }

    public function testRemoveWorktreeKeepsUnrelatedBranches(): void
    {
        $wt = $this->git()->createWorktree($this->clone, $this->root, 'wk-9', 'main');
        RepoHelper::git($this->clone, ['branch', 'other-topic']);

        $this->git()->removeWorktree($this->clone, $wt, 'wk-9');

        $this->assertDirectoryDoesNotExist($wt);
        $names = $this->git()->allBranchNames($this->clone);
        $this->assertNotContains('wk-9', $names);
        $this->assertContains('other-topic', $names);
    }

    public function testRemoveWorktreeToleratesAlreadyDeletedDirectory(): void
    {
        $wt = $this->makeWorktree('wk-9');
        (new \Symfony\Component\Process\Process(['rm', '-rf', $wt]))->run();

        $this->git()->removeWorktree($this->clone, $wt, 'wk-9');

        $this->assertDirectoryDoesNotExist($wt);
        $this->assertStringNotContainsString('wk-9', RepoHelper::git($this->clone, ['worktree', 'list']));
        $this->assertNotContains('wk-9', $this->git()->allBranchNames($this->clone));
    }

    public function testAllBranchNamesIncludesRemote(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::git($wt, ['push', '-q', '-u', 'origin', 'wk-1']);
        $names = $this->git()->allBranchNames($this->clone);
        $this->assertContains('wk-1', $names);
        $this->assertContains('main', $names);
    }

    public function testSyncDryRunReportsBehind(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $headBefore = RepoHelper::git($wt, ['rev-parse', 'HEAD']);
        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', false);
        $this->assertSame('would-sync', $report->action);
        $this->assertSame(1, $report->behind);
        $this->assertSame($headBefore, RepoHelper::git($wt, ['rev-parse', 'HEAD']));
    }

    public function testSyncUpToDate(): void
    {
        $wt = $this->makeWorktree();
        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', false);
        $this->assertSame('up-to-date', $report->action);
    }

    public function testSyncApplyRebasesAndPushesWithLease(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($wt, ['push', '-q', '-u', 'origin', 'wk-1']);
        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true);
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($wt.'/new.txt');
        RepoHelper::git($this->other, ['fetch', '-q', 'origin']);
        $this->assertSame(
            RepoHelper::git($this->other, ['rev-parse', 'origin/wk-1']),
            RepoHelper::git($wt, ['rev-parse', 'HEAD']),
        );
    }

    public function testSyncIntegratesRemoteBranchFirst(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($wt, ['push', '-q', '-u', 'origin', 'wk-1']);
        RepoHelper::git($this->other, ['fetch', '-q', 'origin']);
        RepoHelper::git($this->other, ['checkout', '-q', '-b', 'wk-1', 'origin/wk-1']);
        RepoHelper::commitFile($this->other, 'collab.txt', "c\n", 'collaborator commit');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'wk-1']);
        RepoHelper::git($this->other, ['checkout', '-q', 'main']);
        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true);
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($wt.'/collab.txt');
        $this->assertFileExists($wt.'/new.txt');
    }

    public function testSyncBaseOverrideRebasesOntoStackedParent(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature work');

        // A sibling stack: the parent PR branch is open on origin.
        RepoHelper::git($this->other, ['checkout', '-q', '-b', 'wk-parent']);
        RepoHelper::commitFile($this->other, 'parent.txt', "p\n", 'parent work');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'wk-parent']);
        RepoHelper::git($this->other, ['checkout', '-q', 'main']);

        // Advance main independently; a main-based sync would pull this in.
        RepoHelper::commitFile($this->other, 'main-only.txt', "m\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, base: 'wk-parent');
        $this->assertSame('synced', $report->action);
        $this->assertFileExists($wt.'/feature.txt');
        $this->assertFileExists($wt.'/parent.txt');
        $this->assertFileDoesNotExist($wt.'/main-only.txt');
    }

    public function testSyncConflictAbortsAndReportsFiles(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::commitFile($wt, 'README.md', "local change\n", 'local edit');
        RepoHelper::commitFile($this->other, 'README.md', "remote change\n", 'remote edit');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $headBefore = RepoHelper::git($wt, ['rev-parse', 'HEAD']);

        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true);
        $this->assertSame('conflict', $report->action);
        $this->assertContains('README.md', $report->conflictFiles);
        $this->assertNotSame('', $report->detail);
        $this->assertSame($headBefore, RepoHelper::git($wt, ['rev-parse', 'HEAD']));
        $this->assertSame('', RepoHelper::git($wt, ['status', '--porcelain']));
    }

    public function testSyncDirtyWorktreeSkipped(): void
    {
        $wt = $this->makeWorktree();
        file_put_contents($wt.'/README.md', "uncommitted\n");
        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true);
        $this->assertSame('dirty', $report->action);
    }

    public function testLeaseFailureResetsToOrigHead(): void
    {
        $wt = $this->makeWorktree();
        RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($wt, ['push', '-q', '-u', 'origin', 'wk-1']);
        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
        $origHead = RepoHelper::git($wt, ['rev-parse', 'HEAD']);

        $realPush = new GitRepo()->pushWithLease(...);
        $push = function (string $worktree, string $branch) use ($realPush): void {
            RepoHelper::git($this->other, ['fetch', '-q', 'origin']);
            RepoHelper::git($this->other, ['checkout', '-q', '-b', 'wk-1', 'origin/wk-1']);
            RepoHelper::commitFile($this->other, 'race.txt', "r\n", 'racing commit');
            RepoHelper::git($this->other, ['push', '-q', 'origin', 'wk-1']);
            $realPush($worktree, $branch);
        };

        $report = $this->git()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, $push);
        $this->assertSame('lease-failed', $report->action);
        $this->assertSame($origHead, RepoHelper::git($wt, ['rev-parse', 'HEAD']));
    }
}
