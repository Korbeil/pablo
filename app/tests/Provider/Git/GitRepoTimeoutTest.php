<?php

declare(strict_types=1);

namespace Pablo\Tests\Provider\Git;

use Pablo\Provider\Git\GitRepo;
use Pablo\Support\PabloError;
use Pablo\Tests\Git\RepoHelper;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A git call that runs past its timeout must surface as a PabloError so the
 * sync's lease rollback and rebase abort still run (a raw
 * ProcessTimedOutException skipped both). Slowness comes from real git hooks
 * that sleep, against a GitRepo whose timeouts are shrunk to one second.
 */
final class GitRepoTimeoutTest extends TestCase
{
    private string $tmp;
    private string $clone;
    private string $other;
    private string $wt;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('git-timeout');
        $repos = RepoHelper::makeRepos($this->tmp);
        $this->clone = $repos['clone'];
        $this->other = $repos['other'];

        $this->wt = (new GitRepo())->createWorktree($this->clone, $this->tmp.'/wtroot', 'pr-1', 'main');
        RepoHelper::git($this->wt, ['config', 'user.email', 'test@example.com']);
        RepoHelper::git($this->wt, ['config', 'user.name', 'Test']);
        RepoHelper::commitFile($this->wt, 'feature.txt', "f\n", 'feature');
        RepoHelper::git($this->wt, ['push', '-q', 'origin', 'pr-1']);

        RepoHelper::commitFile($this->other, 'new.txt', "x\n", 'advance main');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);
    }

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->tmp);
    }

    private function hook(string $name, string $body): void
    {
        $path = $this->clone.'/.git/hooks/'.$name;
        file_put_contents($path, "#!/bin/sh\n{$body}\n");
        chmod($path, 0o755);
    }

    public function testPushTimeoutRollsBackAsLeaseFailure(): void
    {
        $this->hook('pre-push', 'sleep 3');
        $origHead = RepoHelper::git($this->wt, ['rev-parse', 'HEAD']);
        $git = new GitRepo([GitRepo::TIMEOUT_NETWORK => 1.0]);

        // The fetch runs under the same shrunk network timeout; a local bare
        // remote answers well within it.
        $report = $git->syncWorktree($this->wt, 'pr-1', 'main', 'rebase', true);

        $this->assertSame('lease-failed', $report->action);
        $this->assertStringContainsString('timed out after 1s', $report->detail);
        $this->assertSame($origHead, RepoHelper::git($this->wt, ['rev-parse', 'HEAD']));
        $this->assertFileDoesNotExist($this->wt.'/new.txt');
    }

    public function testRebaseTimeoutAbortsAndUndoesTheRemoteIntegration(): void
    {
        // A collaborator pushed to pr-1: step 1 integrates it, then step 2
        // (the rebase onto origin/main) hangs in its pre-rebase hook.
        $collab = $this->tmp.'/collab';
        RepoHelper::git($this->tmp, ['clone', '-q', '-b', 'pr-1', $this->tmp.'/origin.git', $collab]);
        RepoHelper::git($collab, ['config', 'user.email', 'test@example.com']);
        RepoHelper::git($collab, ['config', 'user.name', 'Test']);
        RepoHelper::commitFile($collab, 'collab.txt', "c\n", 'collab');
        RepoHelper::git($collab, ['push', '-q', 'origin', 'pr-1']);
        RepoHelper::git($this->wt, ['reset', '-q', '--hard', 'HEAD~1']);
        RepoHelper::commitFile($this->wt, 'mine.txt', "m\n", 'mine');

        $this->hook('pre-rebase', '[ "$1" = "origin/main" ] && sleep 3; exit 0');
        $origHead = RepoHelper::git($this->wt, ['rev-parse', 'HEAD']);
        $git = new GitRepo([GitRepo::TIMEOUT_WORKTREE => 1.0]);

        try {
            $git->syncWorktree($this->wt, 'pr-1', 'main', 'rebase', true, static fn () => null);
            $this->fail('a timed-out rebase must throw a PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('git rebase origin/main timed out after 1s', $e->getMessage());
        }

        $gitDir = RepoHelper::git($this->wt, ['rev-parse', '--absolute-git-dir']);
        $this->assertDirectoryDoesNotExist($gitDir.'/rebase-merge');
        $this->assertDirectoryDoesNotExist($gitDir.'/rebase-apply');
        $this->assertSame($origHead, RepoHelper::git($this->wt, ['rev-parse', 'HEAD']));
        $this->assertFileDoesNotExist($this->wt.'/collab.txt');
        $this->assertSame('', RepoHelper::git($this->wt, ['status', '--porcelain']));
    }

    public function testDefaultTimeoutsAreGenerousAndOrdered(): void
    {
        $t = GitRepo::DEFAULT_TIMEOUTS;
        $this->assertGreaterThan(60.0, $t[GitRepo::TIMEOUT_LOCAL]);
        $this->assertGreaterThanOrEqual($t[GitRepo::TIMEOUT_LOCAL], $t[GitRepo::TIMEOUT_WORKTREE]);
        $this->assertGreaterThanOrEqual($t[GitRepo::TIMEOUT_WORKTREE], $t[GitRepo::TIMEOUT_NETWORK]);
        $this->assertGreaterThanOrEqual($t[GitRepo::TIMEOUT_NETWORK], $t[GitRepo::TIMEOUT_CLONE]);
    }
}
