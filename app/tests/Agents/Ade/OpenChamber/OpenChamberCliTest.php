<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\OpenChamber;

use Pablo\Agents\Ade\OpenChamber\OpenChamberCli;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * `openchamber` is either the CLI (@openchamber/web, a Node script) or the
 * desktop app (a native executable or an AppImage): only the CLI has the
 * `session` commands PABLO drives.
 */
final class OpenChamberCliTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-occli-'.uniqid();
        mkdir($this->tmp, 0o777, true);
    }

    protected function tearDown(): void
    {
        (new Process(['rm', '-rf', $this->tmp]))->run();
    }

    private function file(string $name, string $content): string
    {
        file_put_contents($this->tmp.'/'.$name, $content);

        return $this->tmp.'/'.$name;
    }

    public function testNodeScriptAndShimAreTheCli(): void
    {
        $this->assertFalse(OpenChamberCli::isDesktopApp($this->file('cli.js', "#!/usr/bin/env node\nimport './cli-entry.js';\n")));
        $this->assertFalse(OpenChamberCli::isDesktopApp($this->file('pnpm-shim', "#!/bin/sh\nexec node cli.js \"\$@\"\n")));
        $this->assertNull(OpenChamberCli::refusal($this->tmp.'/cli.js'));
    }

    public function testNativeExecutablesAreTheDesktopApp(): void
    {
        $this->assertTrue(OpenChamberCli::isDesktopApp($this->file('elf', "\x7fELF\x02\x01\x01\x00")));
        $this->assertTrue(OpenChamberCli::isDesktopApp($this->file('macho', "\xcf\xfa\xed\xfe\x07\x00")));
        $this->assertTrue(OpenChamberCli::isDesktopApp($this->file('OpenChamber-2.7.0-x86_64.AppImage', '#!fake')));
    }

    public function testFollowsSymlinks(): void
    {
        $target = $this->file('openchamber-desktop', "\x7fELF\x02\x01\x01\x00");
        symlink($target, $this->tmp.'/openchamber');

        $this->assertTrue(OpenChamberCli::isDesktopApp($this->tmp.'/openchamber'));
        $this->assertStringContainsString('desktop app', (string) OpenChamberCli::refusal($this->tmp.'/openchamber'));
    }

    public function testMissingFileIsNotRefused(): void
    {
        $this->assertFalse(OpenChamberCli::isDesktopApp($this->tmp.'/nope'));
    }
}
