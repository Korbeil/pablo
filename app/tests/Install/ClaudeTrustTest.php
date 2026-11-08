<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\ClaudeTrust;
use PHPUnit\Framework\TestCase;

final class ClaudeTrustTest extends TestCase
{
    private string $tmp;
    private string $file;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-trust-'.bin2hex(random_bytes(4));
        mkdir($this->tmp, 0o777, true);
        $this->file = $this->tmp.'/.claude.json';
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    public function testTheWorktreesFolderIsTrustedAndTheRestOfTheFileKept(): void
    {
        $original = '{"numStartups": 12, "ratio": 1.0, "tipsHistory": {}, "list": [], "projects": {"/home/u/app": {"allowedTools": [], "hasTrustDialogAccepted": true, "mcpServers": {}}}, "userID": "abc"}';
        file_put_contents($this->file, $original);
        chmod($this->file, 0o600);

        $lines = ClaudeTrust::trust(['/home/u/.pablo/worktrees/', '/home/u/.pablo/worktrees/app'], $this->file);

        $this->assertSame(['Claude Code now trusts /home/u/.pablo/worktrees (in '.$this->file.')'], $lines, 'a project root under it is covered already');
        $expected = json_decode($original, true);
        $expected['projects']['/home/u/.pablo/worktrees'] = ['hasTrustDialogAccepted' => true];
        $raw = (string) file_get_contents($this->file);
        $this->assertSame($expected, json_decode($raw, true));
        $this->assertStringContainsString('"tipsHistory": {}', $raw, 'empty objects stay objects');
        $this->assertStringContainsString('"ratio": 1.0', $raw);
        $this->assertSame(0o600, fileperms($this->file) & 0o777);
        $this->assertSame([], glob($this->tmp.'/.claude.json.pablo-*') ?: []);
    }

    public function testAFolderUnderATrustedParentIsLeftAlone(): void
    {
        $original = '{"projects":{"/home/u":{"hasTrustDialogAccepted":true}}}';
        file_put_contents($this->file, $original);

        $this->assertSame([], ClaudeTrust::trust(['/home/u/.pablo/worktrees'], $this->file));
        $this->assertSame($original, file_get_contents($this->file), 'not rewritten');
    }

    public function testAnUntrustedEntryIsCompletedNotReplaced(): void
    {
        file_put_contents($this->file, '{"projects":{"/w":{"hasTrustDialogAccepted":false,"history":[1]}}}');

        ClaudeTrust::trust(['/w'], $this->file);

        $this->assertSame(['projects' => ['/w' => ['hasTrustDialogAccepted' => true, 'history' => [1]]]], json_decode((string) file_get_contents($this->file), true));
    }

    public function testAMissingOrBrokenFileIsNeverWritten(): void
    {
        $this->assertStringContainsString('not found', ClaudeTrust::trust(['/w'], $this->file)[0]);
        $this->assertFileDoesNotExist($this->file);

        file_put_contents($this->file, '{"projects": ');
        $this->assertStringContainsString('not a JSON object', ClaudeTrust::trust(['/w'], $this->file)[0]);
        $this->assertSame('{"projects": ', file_get_contents($this->file));
    }
}
