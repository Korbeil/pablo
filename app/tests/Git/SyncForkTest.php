<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Provider\Git\GitRepo;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Fork setup (`repo.primary_remote: upstream`): the task branch is rebased
 * onto upstream/main but lives on, and is pushed to, the user's fork (origin).
 */
final class SyncForkTest extends TestCase
{
    private string $tmp;
    private string $upstream;
    private string $fork;
    private string $clone;
    private string $maintainer;
    private string $collaborator;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('git-fork');
        $this->upstream = $this->tmp.'/upstream.git';
        $this->fork = $this->tmp.'/fork.git';
        $this->clone = $this->tmp.'/clone';
        $this->maintainer = $this->tmp.'/maintainer';
        $this->collaborator = $this->tmp.'/collaborator';

        GitFixtures::get('fork', $this->tmp, static function (string $dir): void {
            (new Process(['git', 'init', '-q', '--bare', '-b', 'main', $dir.'/upstream.git']))->mustRun();
            self::cloneOf($dir.'/upstream.git', $dir.'/maintainer');
            RepoHelper::commitFile($dir.'/maintainer', 'README.md', "hello\n", 'initial');
            RepoHelper::git($dir.'/maintainer', ['push', '-q', 'origin', 'main']);

            (new Process(['git', 'clone', '-q', '--bare', $dir.'/upstream.git', $dir.'/fork.git']))->mustRun();
            self::cloneOf($dir.'/fork.git', $dir.'/clone');
            RepoHelper::git($dir.'/clone', ['remote', 'add', 'upstream', $dir.'/upstream.git']);
            self::cloneOf($dir.'/fork.git', $dir.'/collaborator');
        });
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private static function cloneOf(string $source, string $dest): void
    {
        (new Process(['git', 'clone', '-q', $source, $dest]))->mustRun();
        RepoHelper::git($dest, ['config', 'user.email', 'test@example.com']);
        RepoHelper::git($dest, ['config', 'user.name', 'Test']);
    }

    /** A task worktree created off upstream/main, its work pushed to the fork without -u (it keeps tracking upstream/main). */
    private function pushedTask(): string
    {
        $wt = new GitRepo()->createWorktree($this->clone, $this->tmp.'/worktrees', 'wk-1', 'main', 'upstream');
        RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($wt, ['push', '-q', 'origin', 'wk-1']);

        return $wt;
    }

    private function advanceUpstreamMain(): void
    {
        RepoHelper::commitFile($this->maintainer, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->maintainer, ['push', '-q', 'origin', 'main']);
    }

    private function forkTip(string $branch = 'wk-1'): string
    {
        return RepoHelper::git($this->fork, ['rev-parse', "refs/heads/{$branch}"]);
    }

    public function testSyncRebasesOntoUpstreamAndPushesToFork(): void
    {
        $wt = $this->pushedTask();
        $this->advanceUpstreamMain();

        $report = new GitRepo()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, remote: 'upstream');

        $this->assertSame('synced', $report->action);
        $this->assertFileExists($wt.'/new.txt');
        $head = RepoHelper::git($wt, ['rev-parse', 'HEAD']);
        $this->assertSame($head, $this->forkTip());
        $this->assertSame(
            RepoHelper::git($this->upstream, ['rev-parse', 'refs/heads/main']),
            RepoHelper::git($wt, ['rev-parse', 'HEAD~1']),
        );
        $this->assertSame('', RepoHelper::git($this->upstream, ['branch', '--list', 'wk-1']));
    }

    public function testSyncIntegratesCollaboratorPushOnTheFork(): void
    {
        $wt = $this->pushedTask();
        RepoHelper::git($this->collaborator, ['fetch', '-q', 'origin']);
        RepoHelper::git($this->collaborator, ['checkout', '-q', '-b', 'wk-1', 'origin/wk-1']);
        RepoHelper::commitFile($this->collaborator, 'collab.txt', "c\n", 'collaborator work');
        RepoHelper::git($this->collaborator, ['push', '-q', 'origin', 'wk-1']);
        $this->advanceUpstreamMain();

        $dry = new GitRepo()->syncWorktree($wt, 'wk-1', 'main', 'rebase', false, remote: 'upstream');
        $this->assertSame('would-sync', $dry->action);
        $this->assertSame('integrate 1 commit(s) from origin/wk-1, then rebase onto upstream/main', $dry->detail);

        $report = new GitRepo()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, remote: 'upstream');
        $this->assertSame('synced', $report->action);
        foreach (['feature.txt', 'collab.txt', 'new.txt'] as $file) {
            $this->assertFileExists($wt.'/'.$file);
        }
        $this->assertSame(RepoHelper::git($wt, ['rev-parse', 'HEAD']), $this->forkTip());
    }

    public function testLocalRewriteOnTheForkIsSkipped(): void
    {
        $wt = $this->pushedTask();
        RepoHelper::git($wt, ['commit', '-q', '--amend', '-m', 'feature work, reworded']);
        $this->advanceUpstreamMain();
        $pushed = $this->forkTip();
        $local = RepoHelper::git($wt, ['rev-parse', 'HEAD']);

        $report = new GitRepo()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, remote: 'upstream');

        $this->assertSame('local-rewrite', $report->action);
        $this->assertStringContainsString('origin/wk-1 still holds the old version', $report->detail);
        $this->assertSame($pushed, $this->forkTip());
        $this->assertSame($local, RepoHelper::git($wt, ['rev-parse', 'HEAD']));
    }

    public function testLeaseIsCheckedAgainstTheFork(): void
    {
        $wt = $this->pushedTask();
        $this->advanceUpstreamMain();
        $origHead = RepoHelper::git($wt, ['rev-parse', 'HEAD']);
        $remotes = [];
        $push = function (string $worktree, string $branch, string $remote) use (&$remotes): void {
            $remotes[] = $remote;
            RepoHelper::git($this->collaborator, ['fetch', '-q', 'origin']);
            RepoHelper::git($this->collaborator, ['checkout', '-q', '-b', 'wk-1', 'origin/wk-1']);
            RepoHelper::commitFile($this->collaborator, 'race.txt', "r\n", 'racing commit');
            RepoHelper::git($this->collaborator, ['push', '-q', 'origin', 'wk-1']);
            new GitRepo()->pushWithLease($worktree, $branch, $remote);
        };

        $report = new GitRepo()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, $push, remote: 'upstream');

        $this->assertSame(['origin'], $remotes);
        $this->assertSame('lease-failed', $report->action);
        $this->assertSame($origHead, RepoHelper::git($wt, ['rev-parse', 'HEAD']));
    }

    public function testConfiguredPushRemoteWins(): void
    {
        // The fork is registered under another name than origin.
        RepoHelper::git($this->clone, ['remote', 'rename', 'origin', 'mine']);
        $wt = new GitRepo()->createWorktree($this->clone, $this->tmp.'/worktrees', 'wk-1', 'main', 'upstream');
        RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature work');
        RepoHelper::git($wt, ['push', '-q', 'mine', 'wk-1']);
        RepoHelper::git($wt, ['config', 'branch.wk-1.pushRemote', 'mine']);
        $this->advanceUpstreamMain();

        $report = new GitRepo()->syncWorktree($wt, 'wk-1', 'main', 'rebase', true, remote: 'upstream');

        $this->assertSame('synced', $report->action);
        $this->assertSame(RepoHelper::git($wt, ['rev-parse', 'HEAD']), $this->forkTip());
    }
}
