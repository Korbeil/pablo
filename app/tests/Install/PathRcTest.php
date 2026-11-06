<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\PathRc;
use PHPUnit\Framework\TestCase;

final class PathRcTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/pablo-pathrc-'.bin2hex(random_bytes(4));
        mkdir($this->home, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    public function testRcFilePerShell(): void
    {
        $this->assertSame($this->home.'/.bashrc', PathRc::rcFile('/bin/bash', $this->home, 'Linux'));
        $this->assertSame($this->home.'/.bash_profile', PathRc::rcFile('bash', $this->home, 'Darwin'));
        $this->assertSame('/z/.zshrc', PathRc::rcFile('/bin/zsh', $this->home, 'Linux', '/z'));
        $this->assertSame($this->home.'/.config/fish/conf.d/pablo.fish', PathRc::rcFile('fish', $this->home, 'Linux'));
        $this->assertNull(PathRc::rcFile('/bin/tcsh', $this->home, 'Linux'));
    }

    public function testAddOnceThenRemoveRestoresTheFile(): void
    {
        $rc = $this->home.'/.bashrc';
        file_put_contents($rc, "alias ll='ls -l'\n");

        $first = PathRc::add('/h/.local/bin', '/usr/bin', $rc);
        $second = PathRc::add('/h/.local/bin', '/usr/bin', $rc);

        $this->assertSame($rc, $first['rc']);
        $this->assertStringContainsString('already puts', $second['message']);
        $this->assertSame(1, substr_count((string) file_get_contents($rc), PathRc::BEGIN));

        $this->assertSame(["removed the PABLO PATH block from {$rc}"], PathRc::remove($this->home));
        $this->assertSame("alias ll='ls -l'\n", file_get_contents($rc));
    }

    public function testNothingToDoWhenTheDirIsOnThePath(): void
    {
        $result = PathRc::add('/h/.local/bin', '/usr/bin:/h/.local/bin', $this->home.'/.bashrc');

        $this->assertNull($result['rc']);
        $this->assertFileDoesNotExist($this->home.'/.bashrc');
    }

    public function testFishGetsAFileOfItsOwn(): void
    {
        $rc = (string) PathRc::rcFile('fish', $this->home, 'Linux');

        PathRc::add('/h/.local/bin', '/usr/bin', $rc);
        $this->assertStringContainsString('set -gx PATH /h/.local/bin $PATH', (string) file_get_contents($rc));

        PathRc::remove($this->home);
        $this->assertFileDoesNotExist($rc);
    }

    public function testAnUnknownShellIsOnlyAdvised(): void
    {
        $result = PathRc::add('/h/.local/bin', '/usr/bin', null);

        $this->assertNull($result['rc']);
        $this->assertStringContainsString('is not on your PATH', $result['message']);
    }
}
