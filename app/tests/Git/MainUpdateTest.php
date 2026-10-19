<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Provider\Git\GitRepo;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class MainUpdateTest extends TestCase
{
    private GitRepo $gitRepo;
    private string $tmp;
    private string $clone;
    private string $other;

    protected function setUp(): void
    {
        $this->gitRepo = new GitRepo();
        $this->tmp = sys_get_temp_dir().'/pablo-main-'.uniqid();
        mkdir($this->tmp, 0o777, true);
        $repos = RepoHelper::makeRepos($this->tmp);
        $this->clone = $repos['clone'];
        $this->other = $repos['other'];
    }

    /** Push a new commit to origin/main from the other clone; returns its sha. */
    private function advanceOrigin(): string
    {
        $sha = RepoHelper::commitFile($this->other, 'upstream.txt', uniqid()."\n", 'upstream');
        RepoHelper::git($this->other, ['push', '-q', 'origin', 'main']);

        return $sha;
    }

    public function testUpdatesMainWhenBehind(): void
    {
        $old = RepoHelper::git($this->clone, ['rev-parse', 'HEAD']);
        $new = $this->advanceOrigin();

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('updated', $report->status);
        $this->assertSame($old, $report->oldSha);
        $this->assertSame($new, $report->newSha);
        $this->assertSame($new, RepoHelper::git($this->clone, ['rev-parse', 'HEAD']));
    }

    public function testUpToDate(): void
    {
        $this->assertSame('up-to-date', $this->gitRepo->updatePrimary($this->clone, 'main')->status);
    }

    public function testSwitchesCleanCheckoutBackToMain(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-q', '-b', 'feature']);
        RepoHelper::commitFile($this->clone, 'feature.txt', "f\n", 'feature');
        $new = $this->advanceOrigin();

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('updated', $report->status);
        $this->assertSame('feature', $report->fromBranch);
        $this->assertSame('main', RepoHelper::git($this->clone, ['branch', '--show-current']));
        $this->assertSame($new, RepoHelper::git($this->clone, ['rev-parse', 'HEAD']));
        // The feature branch itself is left untouched.
        $this->assertTrue($this->gitRepo->refExists($this->clone, 'refs/heads/feature'));
    }

    public function testSwitchesDetachedHeadBackToMain(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-q', '--detach']);
        $new = $this->advanceOrigin();

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('updated', $report->status);
        $this->assertSame('detached HEAD', $report->fromBranch);
        $this->assertSame('main', RepoHelper::git($this->clone, ['branch', '--show-current']));
        $this->assertSame($new, RepoHelper::git($this->clone, ['rev-parse', 'HEAD']));
    }

    public function testCreatesMissingLocalBranch(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-q', '-b', 'feature']);
        RepoHelper::git($this->clone, ['branch', '-q', '-D', 'main']);
        $new = $this->advanceOrigin();

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('updated', $report->status);
        $this->assertSame('main', RepoHelper::git($this->clone, ['branch', '--show-current']));
        $this->assertSame($new, RepoHelper::git($this->clone, ['rev-parse', 'HEAD']));
    }

    public function testSkipsTrackedChangesOnMain(): void
    {
        $this->advanceOrigin();
        file_put_contents($this->clone.'/README.md', "edited\n");
        $head = RepoHelper::git($this->clone, ['rev-parse', 'HEAD']);

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('dirty', $report->status);
        $this->assertSame($head, RepoHelper::git($this->clone, ['rev-parse', 'HEAD']));
        $this->assertSame("edited\n", file_get_contents($this->clone.'/README.md'));
    }

    public function testSkipsUntrackedFiles(): void
    {
        file_put_contents($this->clone.'/scratch.txt', "x\n");

        $this->assertSame('dirty', $this->gitRepo->updatePrimary($this->clone, 'main')->status);
    }

    public function testSkipsDirtyCheckoutOnOtherBranch(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-q', '-b', 'feature']);
        file_put_contents($this->clone.'/README.md', "edited\n");

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('dirty', $report->status);
        $this->assertSame('feature', RepoHelper::git($this->clone, ['branch', '--show-current']));
    }

    public function testSkipsWhenLocalMainIsAhead(): void
    {
        $this->advanceOrigin();
        $local = RepoHelper::commitFile($this->clone, 'local.txt', "l\n", 'unpushed');

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('ahead', $report->status);
        $this->assertSame($local, RepoHelper::git($this->clone, ['rev-parse', 'HEAD']));
    }

    public function testSkipsWhenLocalMainIsAheadWhileOnOtherBranch(): void
    {
        RepoHelper::commitFile($this->clone, 'local.txt', "l\n", 'unpushed');
        RepoHelper::git($this->clone, ['checkout', '-q', '-b', 'feature']);

        $this->assertSame('ahead', $this->gitRepo->updatePrimary($this->clone, 'main')->status);
        $this->assertSame('feature', RepoHelper::git($this->clone, ['branch', '--show-current']));
    }

    public function testSkipsWhenMainIsCheckedOutInAnotherWorktree(): void
    {
        RepoHelper::git($this->clone, ['checkout', '-q', '-b', 'feature']);
        RepoHelper::git($this->clone, ['worktree', 'add', '-q', $this->tmp.'/wt-main', 'main']);

        $report = $this->gitRepo->updatePrimary($this->clone, 'main');

        $this->assertSame('checked-out-elsewhere', $report->status);
        $this->assertSame('feature', RepoHelper::git($this->clone, ['branch', '--show-current']));
    }

    public function testSkipsOperationInProgress(): void
    {
        $gitDir = RepoHelper::git($this->clone, ['rev-parse', '--absolute-git-dir']);
        mkdir($gitDir.'/rebase-merge');

        $this->assertSame('busy', $this->gitRepo->updatePrimary($this->clone, 'main')->status);
    }

    public function testMissingCheckout(): void
    {
        $this->assertSame('missing', $this->gitRepo->updatePrimary($this->tmp.'/nope', 'main')->status);
    }

    public function testUnknownRemoteThrows(): void
    {
        $this->expectException(PabloError::class);
        $this->gitRepo->updatePrimary($this->clone, 'main', 'upstream');
    }
}
