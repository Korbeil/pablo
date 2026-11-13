<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Provider\Git\GitRepo;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

/**
 * A copy of a git fixture must be a world of its own: every remote,
 * worktree link and push resolves inside the copy, never to the template
 * nor to another copy.
 */
final class GitFixturesTest extends TestCase
{
    private const KEY = 'fixtures-test';

    /** @var list<string> */
    private array $dirs = [];

    protected function tearDown(): void
    {
        (new Filesystem())->remove($this->dirs);
    }

    /** @return array{origin: string, clone: string, other: string} */
    private function copy(): array
    {
        $tmp = TempDir::create('git-fixtures');
        $this->dirs[] = $tmp;

        return RepoHelper::makeReposThen(self::KEY, $tmp, self::build(...));
    }

    /** @param array{origin: string, clone: string, other: string} $repos */
    private static function build(string $dir, array $repos): void
    {
        (new GitRepo())->createWorktree($repos['clone'], $dir.'/worktrees', 'wk-1', 'main');
    }

    private static function template(): string
    {
        return GitFixtures::template(self::KEY, static function (string $dir): void {
            self::fail('the template must already exist');
        });
    }

    public function testRemotesAndWorktreesPointIntoTheCopy(): void
    {
        $repos = $this->copy();
        $tmp = \dirname($repos['clone']);
        $wt = $tmp.'/worktrees/wk-1';

        $this->assertSame($repos['origin'], RepoHelper::git($repos['clone'], ['remote', 'get-url', 'origin']));
        $this->assertSame($repos['origin'], RepoHelper::git($repos['other'], ['remote', 'get-url', 'origin']));
        $this->assertSame($repos['origin'], RepoHelper::git($wt, ['remote', 'get-url', 'origin']));
        $this->assertSame(realpath($repos['clone'].'/.git'), realpath(RepoHelper::git($wt, ['rev-parse', '--git-common-dir'])));

        $paths = array_map(
            static fn ($ref): string => (string) realpath($ref->path),
            (new GitRepo())->listWorktrees($repos['clone']),
        );
        $this->assertSame([realpath($repos['clone']), realpath($wt)], $paths);

        // Nothing in the copy still names the template.
        $template = self::template();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tmp, \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            $this->assertStringNotContainsString($template, (string) file_get_contents($file->getPathname()), $file->getPathname());
        }
    }

    public function testPushesFetchesAndWorktreeChangesStayInTheCopy(): void
    {
        $first = $this->copy();
        $second = $this->copy();
        $template = self::template();
        $originMain = RepoHelper::git($template.'/origin.git', ['rev-parse', 'main']);
        $templateWorktrees = RepoHelper::git($template.'/clone', ['worktree', 'list', '--porcelain']);

        $wt = \dirname($first['clone']).'/worktrees/wk-1';
        $pushed = RepoHelper::commitFile($wt, 'feature.txt', "f\n", 'feature');
        RepoHelper::git($wt, ['push', '-q', 'origin', 'wk-1']);
        $advanced = RepoHelper::commitFile($first['other'], 'new.txt', "x\n", 'advance main');
        RepoHelper::git($first['other'], ['push', '-q', 'origin', 'main']);
        RepoHelper::git($first['clone'], ['fetch', '-q', 'origin']);
        (new GitRepo())->removeWorktree($first['clone'], $wt, 'wk-1');

        $this->assertSame($pushed, RepoHelper::git($first['origin'], ['rev-parse', 'wk-1']));
        $this->assertSame($advanced, RepoHelper::git($first['clone'], ['rev-parse', 'origin/main']));
        $this->assertDirectoryDoesNotExist($wt);

        foreach ([$template, \dirname($second['clone'])] as $untouched) {
            $this->assertSame($originMain, RepoHelper::git($untouched.'/origin.git', ['rev-parse', 'main']));
            $this->assertSame('', RepoHelper::git($untouched.'/origin.git', ['branch', '--list', 'wk-1']));
            $this->assertDirectoryExists($untouched.'/worktrees/wk-1');
        }
        $this->assertSame($templateWorktrees, RepoHelper::git($template.'/clone', ['worktree', 'list', '--porcelain']));
    }
}
