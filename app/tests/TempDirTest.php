<?php

declare(strict_types=1);

namespace Pablo\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class TempDirTest extends TestCase
{
    public function testPathsAreUniqueAndUnderOneRootPerProcess(): void
    {
        $a = TempDir::create('t');
        $b = TempDir::create('t');
        $file = TempDir::file('t', '.json');

        $this->assertNotSame($a, $b);
        $this->assertDirectoryExists($a);
        $this->assertFileExists($file);
        $this->assertSame(\dirname($a), \dirname($b));
        $this->assertSame(\dirname($a), \dirname($file));
        $this->assertStringStartsWith(sys_get_temp_dir().'/pablo-tests-', \dirname($a));
    }

    public function testRemoveTreeCopesWithReadOnlyEntriesAndNeverFollowsLinks(): void
    {
        $outside = TempDir::create('outside');
        file_put_contents($outside.'/keep.txt', 'not ours');

        $tree = TempDir::create('tree');
        mkdir($tree.'/objects/ab', 0o777, true);
        file_put_contents($tree.'/objects/ab/cdef', 'blob');
        chmod($tree.'/objects/ab/cdef', 0o444);
        chmod($tree.'/objects/ab', 0o555);
        chmod($tree.'/objects', 0o500);
        symlink($outside, $tree.'/link-to-dir');
        symlink($outside.'/keep.txt', $tree.'/link-to-file');
        symlink($tree.'/missing', $tree.'/dangling');

        TempDir::removeTree($tree);

        $this->assertFileDoesNotExist($tree);
        $this->assertSame('not ours', file_get_contents($outside.'/keep.txt'));
        $this->assertSame(0o777 & ~umask(), fileperms($outside) & 0o777, 'the link target keeps its mode');
    }

    public function testRemoveTreeIgnoresAMissingPath(): void
    {
        TempDir::removeTree(TempDir::create('gone').'/never-created');
        $this->addToAssertionCount(1);
    }

    public function testEverythingIsRemovedAtExitAndAForkedChildKeepsItsParentsDirs(): void
    {
        if (!\function_exists('pcntl_fork')) {
            $this->markTestSkipped('needs ext-pcntl');
        }
        $tmp = TempDir::create('exit');

        $process = $this->runPhp($tmp, <<<'PHP'
            $mine = Pablo\Tests\TempDir::create('parent');
            $pid = pcntl_fork();
            if (0 === $pid) {
                Pablo\Tests\TempDir::create('child');
                exit(0); // runs the inherited shutdown hook in the child
            }
            pcntl_waitpid($pid, $status);
            echo is_dir($mine) ? 'parent dir kept' : 'parent dir lost';
            PHP);

        $this->assertSame('parent dir kept', $process->getOutput(), $process->getErrorOutput());
        $this->assertSame(['.', '..'], scandir($tmp), 'parent and child roots are both gone');
    }

    public function testRootIsRemovedAfterAFatalError(): void
    {
        $tmp = TempDir::create('fatal');

        $process = $this->runPhp($tmp, <<<'PHP'
            Pablo\Tests\TempDir::create('x');
            throw new RuntimeException('boom');
            PHP);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertSame(['.', '..'], scandir($tmp));
    }

    public function testKeepEnvKeepsTheRootAndSaysWhere(): void
    {
        $tmp = TempDir::create('keep');

        $process = $this->runPhp($tmp, 'echo Pablo\Tests\TempDir::create("x");', [TempDir::KEEP_ENV => '1']);

        $this->assertDirectoryExists($process->getOutput());
        $this->assertStringContainsString('test temp files kept in '.\dirname($process->getOutput()), $process->getErrorOutput());
    }

    /** @param array<string, string> $env */
    private function runPhp(string $tmpdir, string $code, array $env = []): Process
    {
        $autoload = \dirname(__DIR__).'/vendor/autoload.php';
        $process = new Process(
            [\PHP_BINARY, '-r', 'require '.var_export($autoload, true).";\n".$code],
            null,
            $env + ['TMPDIR' => $tmpdir, TempDir::KEEP_ENV => false],
        );
        $process->run();

        return $process;
    }
}
