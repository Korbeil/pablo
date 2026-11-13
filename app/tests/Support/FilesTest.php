<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\Files;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class FilesTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-files-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/src/skills/b', 0o777, true);
        file_put_contents($this->tmp.'/src/b.md', 'b');
        file_put_contents($this->tmp.'/src/a.md', 'a');
        file_put_contents($this->tmp.'/src/c.txt', 'c');
        file_put_contents($this->tmp.'/src/skills/b/SKILL.md', 'skill');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testListMatchesSortsAndFiltersDirectories(): void
    {
        $this->assertSame([$this->tmp.'/src/a.md', $this->tmp.'/src/b.md'], Files::list($this->tmp.'/src', '*.md'));
        $this->assertSame([$this->tmp.'/src/skills'], Files::list($this->tmp.'/src', '*', dirsOnly: true));
        $this->assertSame([], Files::list($this->tmp.'/missing'));
    }

    /** glob() sees nothing inside a phar; Files::list() must. */
    public function testListWorksInsideAPhar(): void
    {
        // phar.readonly cannot be lifted at runtime: build it in a subprocess.
        $build = '$p = new Phar($argv[1]); $p->addFromString("opencode/commands/x.md", "x"); $p->addFromString("opencode/skills/s/SKILL.md", "s");';
        exec(escapeshellarg(\PHP_BINARY).' -d phar.readonly=0 -r '.escapeshellarg($build).' '.escapeshellarg($this->tmp.'/t.phar'), $out, $code);
        $this->assertSame(0, $code, implode("\n", $out));

        $root = 'phar://'.$this->tmp.'/t.phar/opencode';
        $this->assertSame([$root.'/commands/x.md'], Files::list($root.'/commands', '*.md'));
        $this->assertSame([], glob($root.'/commands/*.md') ?: []);
        Files::copy($root.'/skills/s', $this->tmp.'/out/s');
        $this->assertSame('s', file_get_contents($this->tmp.'/out/s/SKILL.md'));
    }

    public function testCopyIsRecursive(): void
    {
        Files::copy($this->tmp.'/src', $this->tmp.'/dst');

        $this->assertSame('skill', file_get_contents($this->tmp.'/dst/skills/b/SKILL.md'));
        $this->assertSame('a', file_get_contents($this->tmp.'/dst/a.md'));
    }

    public function testWriteReplacesASymlinkInsteadOfWritingThroughIt(): void
    {
        file_put_contents($this->tmp.'/target', 'original');
        symlink($this->tmp.'/target', $this->tmp.'/link');

        Files::write($this->tmp.'/link', 'new', 0o755);

        $this->assertFalse(is_link($this->tmp.'/link'));
        $this->assertSame('new', file_get_contents($this->tmp.'/link'));
        $this->assertSame('original', file_get_contents($this->tmp.'/target'));
        $this->assertTrue(is_executable($this->tmp.'/link'));
    }

    public function testAShortWriteThrowsKeepsThePreviousFileAndRemovesTheTempFile(): void
    {
        mkdir($this->tmp.'/d');
        file_put_contents($this->tmp.'/d/record.json', 'previous');
        ShortWriteStream::$root = $this->tmp;
        stream_wrapper_register('shortwrite', ShortWriteStream::class);
        try {
            Files::write('shortwrite://d/record.json', 'a longer replacement that does not fit');
            $this->fail('expected PabloError');
        } catch (PabloError $e) {
            $this->assertStringContainsString('cannot write shortwrite://d/record.json', $e->getMessage());
        } finally {
            stream_wrapper_unregister('shortwrite');
        }

        $this->assertSame('previous', file_get_contents($this->tmp.'/d/record.json'));
        $this->assertSame([$this->tmp.'/d/record.json'], Files::list($this->tmp.'/d'), 'temp file removed');
    }

    public function testWriteIsAtomicAndLeavesNoTempFile(): void
    {
        Files::write($this->tmp.'/out.txt', 'one');
        Files::write($this->tmp.'/out.txt', 'two');

        $this->assertSame('two', file_get_contents($this->tmp.'/out.txt'));
        $this->assertSame([$this->tmp.'/out.txt'], Files::list($this->tmp, 'out.txt*'));
    }

    public function testRemoveDeletesATreeWithDotfiles(): void
    {
        file_put_contents($this->tmp.'/src/skills/.hidden', 'h');

        Files::remove($this->tmp.'/src');

        $this->assertDirectoryDoesNotExist($this->tmp.'/src');
    }

    public function testEnsureDirCreatesParentsAndAcceptsAnExistingDir(): void
    {
        Files::ensureDir($this->tmp.'/a/b/c');
        Files::ensureDir($this->tmp.'/a/b/c');

        $this->assertDirectoryExists($this->tmp.'/a/b/c');
        $this->assertTrue(Files::tryEnsureDir($this->tmp.'/a/b/c'));
    }

    public function testEnsureDirNamesThePathItCannotCreate(): void
    {
        // A regular file in the way blocks mkdir() even for root.
        $dir = $this->tmp.'/src/a.md/sub';

        $this->assertFalse(Files::tryEnsureDir($dir));
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage($dir);
        Files::ensureDir($dir);
    }

    public function testEnsureDirFailsInAnUnwritableDirectory(): void
    {
        if (\function_exists('posix_geteuid') && 0 === posix_geteuid()) {
            $this->markTestSkipped('root ignores directory permissions');
        }
        mkdir($this->tmp.'/locked', 0o500);
        try {
            $this->expectException(PabloError::class);
            $this->expectExceptionMessage($this->tmp.'/locked/sub');
            Files::ensureDir($this->tmp.'/locked/sub');
        } finally {
            chmod($this->tmp.'/locked', 0o700);
        }
    }

    public function testReadAndTryRead(): void
    {
        $this->assertSame('a', Files::read($this->tmp.'/src/a.md'));
        $this->assertSame('a', Files::tryRead($this->tmp.'/src/a.md'));
        $this->assertNull(Files::tryRead($this->tmp.'/missing'));

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('cannot read '.$this->tmp.'/missing');
        Files::read($this->tmp.'/missing');
    }

    public function testTryRemoveIsTrueOnceTheFileIsGone(): void
    {
        symlink($this->tmp.'/nowhere', $this->tmp.'/dangling');

        $this->assertTrue(Files::tryRemove($this->tmp.'/src/a.md'));
        $this->assertFileDoesNotExist($this->tmp.'/src/a.md');
        $this->assertTrue(Files::tryRemove($this->tmp.'/dangling'));
        $this->assertFalse(is_link($this->tmp.'/dangling'));
        $this->assertTrue(Files::tryRemove($this->tmp.'/missing'));
        $this->assertFalse(Files::tryRemove($this->tmp.'/src/skills'), 'a directory is not removed');
    }
}
