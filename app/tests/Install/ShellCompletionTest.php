<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use Pablo\Install\InstallLog;
use Pablo\Install\ShellCompletion;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ShellCompletionTest extends TestCase
{
    private string $home;

    protected function setUp(): void
    {
        $this->home = TempDir::create('completion');
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

        $this->assertStringContainsString("+ created  {$path}", $this->install($shell));
        $script = (string) file_get_contents($path);
        $this->assertStringContainsString(ShellCompletion::MARKER, $script);
        $this->assertStringContainsString('_sf_pablo', $script);
        $this->assertStringNotContainsString('{{', $script);

        $this->assertStringContainsString("= unchanged {$path}", $this->install($shell));
        $this->assertSame($script, file_get_contents($path));

        $this->assertSame(["removed {$path}"], ShellCompletion::remove($this->home));
        $this->assertFileDoesNotExist($path);
    }

    public function testZshKeepsCompdefOnTheFirstLine(): void
    {
        $lines = explode("\n", ShellCompletion::script('zsh'));

        $this->assertSame('#compdef pablo', $lines[0]);
        $this->assertSame(ShellCompletion::MARKER, $lines[1]);
        $this->assertStringContainsString('fpath', $this->install('zsh'));
    }

    public function testAStaleScriptOfOursIsRewritten(): void
    {
        $path = (string) ShellCompletion::path('bash', $this->home);
        mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, ShellCompletion::MARKER."\nold\n");

        $this->assertStringContainsString('~ updated', $this->install('bash'));
        $this->assertSame(ShellCompletion::script('bash'), file_get_contents($path));
    }

    public function testAFileOrLinkOfTheUsersIsNeverTouched(): void
    {
        $path = (string) ShellCompletion::path('fish', $this->home);
        mkdir(\dirname($path), 0o777, true);
        file_put_contents($path, "# mine\n");

        $this->assertStringContainsString('is not PABLO\'s: left alone', $this->install('fish'));
        $this->assertSame([], ShellCompletion::remove($this->home));
        $this->assertSame("# mine\n", file_get_contents($path));

        $bash = (string) ShellCompletion::path('bash', $this->home);
        mkdir(\dirname($bash), 0o777, true);
        file_put_contents($this->home.'/target', ShellCompletion::script('bash'));
        symlink($this->home.'/target', $bash);

        $this->assertStringContainsString('left alone', $this->install('bash'));
        $this->assertSame([], ShellCompletion::remove($this->home));
        $this->assertTrue(is_link($bash));
    }

    public function testAnUnknownShellGetsNothing(): void
    {
        $this->assertNull(ShellCompletion::path('/bin/tcsh', $this->home));
        $this->assertStringContainsString('no shell completion installed for tcsh', $this->install('/bin/tcsh'));
        $this->assertStringContainsString('an unknown shell', $this->install(''));
    }

    public function testTheLoadingNotesOnlyShowWhenTheScriptChanged(): void
    {
        $this->assertStringContainsString('bash-completion package', $this->install('bash'));
        $this->assertStringNotContainsString('bash-completion package', $this->install('bash'));
        $this->assertStringContainsString('bash-completion package', $this->install('bash', OutputInterface::VERBOSITY_VERBOSE));
    }

    /** What install() reported, as printed by `pablo system:install`. */
    private function install(string $shell, int $verbosity = OutputInterface::VERBOSITY_NORMAL): string
    {
        $output = new BufferedOutput($verbosity);
        $log = new InstallLog($output);
        ShellCompletion::install($shell, $this->home, $log);
        $log->close();

        return $output->fetch();
    }
}
