<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * site/installer (served at https://getpablo.dev/installer): prerequisites,
 * detection of the agent runtimes and environments, the questions, the
 * verified download and the hand-over to `pablo system:install`.
 *
 * The "release" is a local directory served through file:// (real curl, real
 * sha256sum); its pablo-<target> is a script that records the arguments
 * system:install received.
 */
final class InstallerTest extends TestCase
{
    use ShellSandbox;

    private const PREREQUISITES_URL = 'https://getpablo.dev/docs/installation/#prerequisites';

    private string $installer;
    private string $releases;
    private string $target;

    protected function setUp(): void
    {
        $this->installer = \dirname(__DIR__, 3).'/site/installer';
        $this->createSandbox();
        $this->target = self::target();
        $this->releases = $this->sandbox.'/releases';
        $this->publish('latest/download', 'v9.9.9');
    }

    protected function tearDown(): void
    {
        $this->removeSandbox();
    }

    public function testScriptIsSyntacticallyValid(): void
    {
        $process = $this->runInSandbox([$this->realTool('bash'), '-n', $this->installer]);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
    }

    public function testHelp(): void
    {
        $process = $this->install(['--help']);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('--version', $process->getOutput());
        $this->assertStringContainsString('--runtime', $process->getOutput());
    }

    public function testUnknownOptionIsRefused(): void
    {
        $process = $this->install(['--install-dir', '/tmp/x']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Unknown option --install-dir', $this->outputOf($process));
    }

    public function testNoRuntimeFoundPointsToThePrerequisites(): void
    {
        $this->fakeTool('orca');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('No agent runtime found', $this->outputOf($process));
        $this->assertStringContainsString(self::PREREQUISITES_URL, $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testNeitherPhpNorComposerIsNeeded(): void
    {
        unlink($this->sandbox.'/path/php');
        $this->fakeTool('claude');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringNotContainsString('PHP', $this->outputOf($process));
        $this->assertStringNotContainsString('Composer', $this->outputOf($process));
    }

    public function testMissingCurlPointsToThePrerequisites(): void
    {
        unlink($this->sandbox.'/path/curl');
        $this->fakeTool('claude');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('curl is required', $this->outputOf($process));
        $this->assertStringContainsString(self::PREREQUISITES_URL, $this->outputOf($process));
    }

    public function testTheVerifiedBinaryIsInstalledAndHandedOver(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('orca');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('sha256 verified', $this->outputOf($process));
        $this->assertTrue(is_executable($this->bin()));
        $this->assertStringContainsString('v9.9.9', (string) file_get_contents($this->bin()));
        $this->assertSame('--runtime claude-code --backend orca', $this->installArguments());
        $this->assertStringContainsString('Uninstall  pablo system:uninstall', $this->outputOf($process));
    }

    public function testAChecksumMismatchInstallsNothing(): void
    {
        $this->fakeTool('claude');
        file_put_contents($this->releases.'/latest/download/sha256sums.txt', str_repeat('0', 64)."  pablo-{$this->target}\n");

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Checksum mismatch', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testABinaryMissingFromTheChecksumsIsRefused(): void
    {
        $this->fakeTool('claude');
        file_put_contents($this->releases.'/latest/download/sha256sums.txt', '');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('not listed in sha256sums.txt', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testVersionInstallsThatRelease(): void
    {
        $this->fakeTool('claude');
        $this->publish('download/v1.0.0', 'v1.0.0');

        $process = $this->install(['--no-interaction', '--version', 'v1.0.0']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('v1.0.0', (string) file_get_contents($this->bin()));
    }

    public function testUnknownVersionFails(): void
    {
        $this->fakeTool('claude');

        $process = $this->install(['--no-interaction', '--version', 'v0.0.0-nope']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('is v0.0.0-nope a PABLO release?', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testSingleRuntimeAndOrcaAreUsedWithoutAsking(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('orca');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime claude-code --backend orca', $this->installArguments());
    }

    public function testOpenChamberIsUsedWithOpenCodeWhenOrcaIsMissing(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('openchamber');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime opencode --backend openchamber', $this->installArguments());
    }

    public function testOpenChamberIsNotOfferedWithClaudeCode(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('openchamber');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('cannot run Claude Code', $this->outputOf($process));
        $this->assertSame('--runtime claude-code --backend headless', $this->installArguments());
    }

    public function testNoEnvironmentFallsBackToHeadlessAndPointsToThePrerequisites(): void
    {
        $this->fakeTool('copilot');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('agents can run headless', $this->outputOf($process));
        $this->assertStringContainsString(self::PREREQUISITES_URL, $this->outputOf($process));
        $this->assertSame('--runtime copilot --backend headless', $this->installArguments());
    }

    public function testExplicitChoicesWinOverDetection(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('claude');
        $this->fakeTool('orca');

        $process = $this->install(['--no-interaction', '--runtime=claude-code', '--backend', 'headless']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime claude-code --backend headless', $this->installArguments());
    }

    public function testOpenChamberWithAnotherRuntimeThanOpenCodeIsRefused(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('openchamber');

        $process = $this->install(['--no-interaction', '--backend', 'openchamber']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testUnknownRuntimeAndBackendAreRefused(): void
    {
        $this->fakeTool('claude');

        $runtime = $this->install(['--no-interaction', '--runtime', 'vim']);
        $backend = $this->install(['--no-interaction', '--backend', 'vim']);

        $this->assertNotSame(0, $runtime->getExitCode());
        $this->assertStringContainsString("Unknown --runtime 'vim'", $this->outputOf($runtime));
        $this->assertNotSame(0, $backend->getExitCode());
        $this->assertStringContainsString("Unknown --backend 'vim'", $this->outputOf($backend));
    }

    public function testWithoutWebIsForwarded(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('orca');

        $process = $this->install(['--no-interaction', '--without-web']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime claude-code --backend orca --without-web', $this->installArguments());
    }

    public function testACloneInstallIsMigrated(): void
    {
        $this->fakeTool('claude');
        mkdir($this->sandbox.'/home/.local/bin', 0o777, true);
        file_put_contents($this->bin(), "#!/usr/bin/env bash\nexec php '{$this->sandbox}/old/app/bin/pablo' \"\$@\"\n");
        chmod($this->bin(), 0o755);

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString("PABLO is installed from a checkout in {$this->sandbox}/old", $this->outputOf($process));
        $this->assertStringContainsString("The checkout in {$this->sandbox}/old is no longer used", $this->outputOf($process));
        $this->assertStringNotContainsString('exec php', (string) file_get_contents($this->bin()));
        $this->assertSame('--runtime claude-code --backend headless', $this->installArguments());
    }

    public function testRunningAgainUpdatesTheBinary(): void
    {
        $this->fakeTool('claude');
        $this->assertSame(0, $this->install(['--no-interaction'])->getExitCode());
        $this->publish('latest/download', 'v10.0.0');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString("v9.9.9 is installed in {$this->bin()}: it will be updated", $this->outputOf($process));
        $this->assertStringContainsString('v10.0.0', (string) file_get_contents($this->bin()));
    }

    public function testAForeignPabloCommandStopsTheInstaller(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('pablo', 'echo something else');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('A pablo command already exists at '.$this->sandbox.'/path/pablo and is not PABLO', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testAnotherPabloBinaryOnThePathIsOnlyWarnedAbout(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('pablo', 'echo "pablo 1111111"');

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('Another PABLO is on your PATH at '.$this->sandbox.'/path/pablo (pablo 1111111)', $this->outputOf($process));
        $this->assertStringContainsString('v9.9.9', (string) file_get_contents($this->bin()));
    }

    public function testAnOldShimOnThePathIsTakenAsTheCheckoutToMigrate(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('pablo', "exec php '/srv/old-clone/app/bin/pablo' \"\$@\"");

        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('The checkout in /srv/old-clone is no longer used', $this->outputOf($process));
    }

    public function testTheDownloadIsStagedNextToTheBinaryAndCleanedUp(): void
    {
        $this->fakeTool('claude');

        $this->assertSame(0, $this->install(['--no-interaction'])->getExitCode());

        $this->assertSame(['pablo'], array_values(array_diff(scandir($this->sandbox.'/home/.local/bin') ?: [], ['.', '..'])));
    }

    public function testSystemInstallFailureIsReported(): void
    {
        $this->fakeTool('claude');
        $this->publish('latest/download', 'v9.9.9', "echo boom >&2\nexit 3");

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('pablo system:install failed', $this->outputOf($process));
    }

    public function testInteractiveAnswers(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('claude');
        $this->fakeTool('orca');
        $this->fakeTool('openchamber');
        // runtime 2 = Claude Code, environment 2 = headless (OpenChamber is not offered), no dashboard, go
        $process = $this->interactive("2\n2\nn\ny\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime claude-code --backend headless --without-web', $this->installArguments());
        $this->assertStringNotContainsString('1) OpenChamber', $this->outputOf($process));
    }

    public function testInteractiveMenuOffersOpenChamberWithOpenCode(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('orca');
        $this->fakeTool('openchamber');
        // environments: 1 Orca, 2 OpenChamber, 3 headless
        $process = $this->interactive("2\n\n\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('2) OpenChamber', $this->outputOf($process));
        $this->assertSame('--runtime opencode --backend openchamber', $this->installArguments());
    }

    public function testInteractiveInvalidAnswerAsksAgain(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('claude');
        // runtime: 9, abc (refused), 2 = Claude Code; environment: default; dashboard: default; go: default
        $process = $this->interactive("9\nabc\n2\n\n\n\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('Answer with a number between 1 and 2', $this->outputOf($process));
        $this->assertSame('--runtime claude-code --backend headless', $this->installArguments());
    }

    public function testInteractiveDefaultsToTheRuntimeOfAnExistingConfig(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('copilot');
        mkdir($this->sandbox.'/home/.pablo', 0o777, true);
        file_put_contents($this->sandbox.'/home/.pablo/config.yaml', "agent_runtime: copilot\n");

        $process = $this->interactive("\n\n\n\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime copilot --backend headless', $this->installArguments());
    }

    public function testInteractiveWithoutEnvironmentCanStopToInstallOne(): void
    {
        $this->fakeTool('claude');

        // environment: 2 = stop here
        $process = $this->interactive("2\n");

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('2) Stop here', $this->outputOf($process));
        $this->assertStringContainsString('Install Orca or OpenChamber', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    public function testInteractiveNoAbortsBeforeAnythingIsInstalled(): void
    {
        $this->fakeTool('claude');

        // environment: default (headless), dashboard: default, go: no
        $process = $this->interactive("\n\nn\n");

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('nothing was installed', $this->outputOf($process));
        $this->assertFileDoesNotExist($this->bin());
    }

    /** @param list<string> $arguments */
    private function install(array $arguments): Process
    {
        return $this->runInSandbox(
            [$this->realTool('bash'), $this->installer, ...$arguments],
            ['PABLO_RELEASES_URL' => 'file://'.$this->releases],
        );
    }

    private function interactive(string $answers): Process
    {
        file_put_contents($this->sandbox.'/answers', $answers);

        return $this->runInSandbox(
            [$this->realTool('bash'), $this->installer],
            ['PABLO_RELEASES_URL' => 'file://'.$this->releases, 'PABLO_INSTALLER_TTY' => $this->sandbox.'/answers'],
        );
    }

    private function bin(): string
    {
        return $this->sandbox.'/home/.local/bin/pablo';
    }

    private function installArguments(): string
    {
        $file = $this->sandbox.'/home/install-args';
        $this->assertFileExists($file, 'pablo system:install was never run');

        return trim((string) file_get_contents($file));
    }

    /**
     * A release directory ($path under the releases root) holding a fake
     * binary for this machine and its sha256sums.txt.
     */
    private function publish(string $path, string $version, string $onInstall = 'printf \'%s\n\' "$*" > "$HOME/install-args"'): void
    {
        $dir = $this->releases.'/'.$path;
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        $binary = <<<BASH
            #!/usr/bin/env bash
            # pablo {$version}
            case "\$1" in
            --version) echo "pablo {$version}" ;;
            system:install)
                shift
                {$onInstall}
                ;;
            esac
            BASH;
        file_put_contents($dir.'/pablo-'.$this->target, $binary."\n");
        file_put_contents($dir.'/sha256sums.txt', hash_file('sha256', $dir.'/pablo-'.$this->target)."  pablo-{$this->target}\n");
    }

    private static function target(): string
    {
        $arch = php_uname('m');
        if ('Darwin' === \PHP_OS_FAMILY) {
            return 'macos-'.('x86_64' === $arch ? 'x86_64' : 'arm64');
        }

        return 'linux-'.(\in_array($arch, ['aarch64', 'arm64'], true) ? 'aarch64' : 'x86_64');
    }
}
