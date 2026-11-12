<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\ShellCompletion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ShellCompletionTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/pablo-completion-'.bin2hex(random_bytes(4));
        mkdir($this->home, 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->home));
    }

    /** @return iterable<string, array{string, string}> */
    public static function shells(): iterable
    {
        yield 'bash' => ['/bin/bash', '/.local/share/bash-completion/completions/pablo'];
        yield 'zsh' => ['/usr/bin/zsh', '/.local/share/zsh/site-functions/_pablo'];
        yield 'fish' => ['fish', '/.config/fish/completions/pablo.fish'];
    }

    #[DataProvider('shells')]
    public function testInstallsTheScriptOfTheShellAndRemovesIt(string $shell, string $relative): void
    {
        $path = $this->home.$relative;

        $messages = ShellCompletion::install($shell, $this->home);

        $this->assertStringContainsString("completion: {$path}", $messages[0]);
        $script = (string) file_get_contents($path);
        $this->assertStringContainsString(ShellCompletion::MARKER, $script);
        $this->assertStringContainsString('_sf_pablo', $script);
        $this->assertStringNotContainsString('{{', $script);

        $this->assertStringContainsString('is up to date', ShellCompletion::install($shell, $this->home)[0]);
        $this->assertSame($script, file_get_contents($path));

        $this->assertSame(["removed {$path}"], ShellCompletion::remove($this->home));
        $this->assertFileDoesNotExist($path);
    }

    public function testZshKeepsCompdefOnTheFirstLine(): void
    {
        $lines = explode("\n", ShellCompletion::script('zsh'));

        $this->assertSame('#compdef pablo', $lines[0]);
        $this->assertSame(ShellCompletion::MARKER, $lines[1]);
        $this->assertStringContainsString('fpath', implode("\n", ShellCompletion::install('zsh', $this->home)));
    }

    public function testAStaleScriptOfOursIsRewritten(): void
    {
        $path = (string) ShellCompletion::path('bash', $this->home);
        mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, ShellCompletion::MARKER."\nold\n");

        $this->assertStringContainsString('installed the bash completion', ShellCompletion::install('bash', $this->home)[0]);
        $this->assertSame(ShellCompletion::script('bash'), file_get_contents($path));
    }

    public function testAFileOrLinkOfTheUsersIsNeverTouched(): void
    {
        $path = (string) ShellCompletion::path('fish', $this->home);
        mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, "# mine\n");

        $this->assertStringContainsString('is not PABLO\'s: left alone', ShellCompletion::install('fish', $this->home)[0]);
        $this->assertSame([], ShellCompletion::remove($this->home));
        $this->assertSame("# mine\n", file_get_contents($path));

        $bash = (string) ShellCompletion::path('bash', $this->home);
        mkdir(\dirname($bash), 0o777, true);
        file_put_contents($this->home.'/target', ShellCompletion::script('bash'));
        symlink($this->home.'/target', $bash);

        $this->assertStringContainsString('left alone', ShellCompletion::install('bash', $this->home)[0]);
        $this->assertSame([], ShellCompletion::remove($this->home));
        $this->assertTrue(is_link($bash));
    }

    public function testAnUnknownShellGetsNothing(): void
    {
        $this->assertNull(ShellCompletion::path('/bin/tcsh', $this->home));
        $this->assertStringContainsString('no shell completion installed for tcsh', ShellCompletion::install('/bin/tcsh', $this->home)[0]);
        $this->assertStringContainsString('an unknown shell', ShellCompletion::install('', $this->home)[0]);
    }
}
