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
 * that sleep, against a GitRepo whose timeouts are shrunk to TIMEOUT seconds.
 * The shrunk timeout covers every git call of its kind, not only the one that
 * hangs, so it keeps a margin for the others on a loaded machine (a parallel
 * run): one second was not always enough for a `git reset --hard`.
 */
final class GitRepoTimeoutTest extends TestCase
{
    /** The shrunk timeout, in seconds; the hooks sleep far longer. */
    private const int TIMEOUT = 5;
    private const int HOOK_SLEEP = 30;

    private string $tmp;
    private string $clone;
    private string $wt;

    protected function setUp(): void
    {
        $this->tmp = TempDir::create('git-timeout');
        // The task worktree pr-1 with one pushed commit, then main advanced on origin.
        $repos = RepoHelper::makeReposThen('timeout', $this->tmp, static function (string $dir, array $repos): void {
            $wt = (new GitRepo())->createWorktree($repos['clone'], $dir.'/wtroot', 'pr-1', 'main');
            RepoHelper::git($wt, ['config', 'user.email', 'test@example.com']);
            RepoHelper::git($wt, ['config', 'user.name', 'Test']);
            RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature');
            RepoHelper::git($wt, ['push', '-q', 'origin', 'pr-1']);

            RepoHelper::commitFile($repos['other'], 'new.txt', "x\n", 'advance main');
            RepoHelper::git($repos['other'], ['push', '-q', 'origin', 'main']);
        });
        $this->clone = $repos['clone'];
        $this->wt = $this->tmp.'/wtroot/pr-1';
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
        $this->hook('pre-push', 'sleep '.self::HOOK_SLEEP);
        $origHead = RepoHelper::git($this->wt, ['rev-parse', 'HEAD']);
        $git = new GitRepo([GitRepo::TIMEOUT_NETWORK => (float) self::TIMEOUT]);

        // The fetch runs under the same shrunk network timeout; a local bare
        // remote answers well within it.
        $report = $git->syncWorktree($this->wt, 'pr-1', 'main', 'rebase', true);

        $this->assertSame('lease-failed', $report->action);
        $this->assertStringContainsString('timed out after '.self::TIMEOUT.'s', $report->detail);
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

        $this->hook('pre-rebase', '[ "$1" = "origin/main" ] && sleep '.self::HOOK_SLEEP.'; exit 0');
        $origHead = RepoHelper::git($this->wt, ['rev-parse', 'HEAD']);
        $git = new GitRepo([GitRepo::TIMEOUT_WORKTREE => (float) self::TIMEOUT]);

        try {
            $git->syncWorktree($this->wt, 'pr-1', 'main', 'rebase', true, static fn () => null);
            $this->fail('a timed-out rebase must throw a PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('git rebase origin/main timed out after '.self::TIMEOUT.'s', $e->getMessage());
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
