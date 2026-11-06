<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\Links;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class LinksTest extends TestCase
{
    private string $tmp;
    private Links $links;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-links-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/runtime/opencode/agents', 0o777, true);
        mkdir($this->tmp.'/dest', 0o777, true);
        file_put_contents($this->tmp.'/runtime/opencode/agents/a.md', "a\n");
        $this->links = new Links($this->tmp.'/runtime');
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testLinksAndRelinksIdempotently(): void
    {
        $source = $this->tmp.'/runtime/opencode/agents/a.md';

        $this->links->link($source, $this->tmp.'/dest/sub/a.md');
        $this->links->link($source, $this->tmp.'/dest/sub/a.md');

        $this->assertSame($source, readlink($this->tmp.'/dest/sub/a.md'));
        $this->assertTrue($this->links->isOurs($this->tmp.'/dest/sub/a.md'));
    }

    public function testADanglingLinkIsReplaced(): void
    {
        symlink($this->tmp.'/nowhere', $this->tmp.'/dest/a.md');

        $this->links->link($this->tmp.'/runtime/opencode/agents/a.md', $this->tmp.'/dest/a.md');

        $this->assertSame($this->tmp.'/runtime/opencode/agents/a.md', readlink($this->tmp.'/dest/a.md'));
    }

    public function testAFileOrALinkOfTheUsersIsRefused(): void
    {
        file_put_contents($this->tmp.'/dest/file.md', "mine\n");
        file_put_contents($this->tmp.'/mine.md', "mine\n");
        symlink($this->tmp.'/mine.md', $this->tmp.'/dest/link.md');

        foreach (['file.md', 'link.md'] as $name) {
            try {
                $this->links->link($this->tmp.'/runtime/opencode/agents/a.md', $this->tmp.'/dest/'.$name);
                $this->fail("{$name} was overwritten");
            } catch (PabloError $e) {
                $this->assertStringContainsString('refusing to overwrite', $e->getMessage());
            }
        }
        $this->assertSame("mine\n", file_get_contents($this->tmp.'/dest/file.md'));
        $this->assertSame($this->tmp.'/mine.md', readlink($this->tmp.'/dest/link.md'));
    }

    public function testLinksIntoACheckoutAreOurs(): void
    {
        mkdir($this->tmp.'/clone/app/bin', 0o777, true);
        mkdir($this->tmp.'/clone/opencode/agents', 0o777, true);
        file_put_contents($this->tmp.'/clone/app/bin/pablo', '');
        file_put_contents($this->tmp.'/clone/opencode/agents/a.md', '');
        symlink($this->tmp.'/clone/opencode/agents/a.md', $this->tmp.'/dest/a.md');

        $this->assertTrue($this->links->isOurs($this->tmp.'/dest/a.md'));
        $this->assertSame($this->tmp.'/clone', Links::legacyCheckout($this->tmp.'/clone/opencode/agents/a.md'));
        $this->assertTrue($this->links->removeIfOurs($this->tmp.'/dest/a.md'));
        $this->assertFileExists($this->tmp.'/clone/opencode/agents/a.md');
    }

    public function testRemoveIfOursLeavesEverythingElse(): void
    {
        file_put_contents($this->tmp.'/dest/file.md', "mine\n");
        symlink($this->tmp.'/somewhere/else.md', $this->tmp.'/dest/link.md');

        $this->assertFalse($this->links->removeIfOurs($this->tmp.'/dest/file.md'));
        $this->assertFalse($this->links->removeIfOurs($this->tmp.'/dest/link.md'));
        $this->assertFalse($this->links->removeIfOurs($this->tmp.'/dest/missing.md'));
        $this->assertFileExists($this->tmp.'/dest/file.md');
        $this->assertTrue(is_link($this->tmp.'/dest/link.md'));
    }
}
