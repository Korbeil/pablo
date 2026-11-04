<?php

declare(strict_types=1);

namespace Pablo\Tests\Install;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * site/installer (served at https://getpablo.dev/installer): prerequisites,
 * detection of the agent runtimes and environments, the questions, and the
 * hand-over to bin/install.sh. The checkout is a local git repository whose
 * bin/install.sh only records the arguments it received.
 */
final class InstallerTest extends TestCase
{
    use ShellSandbox;

    private const PREREQUISITES_URL = 'https://getpablo.dev/docs/installation/#prerequisites';

    private string $installer;
    private string $origin;
    private string $installDir;

    protected function setUp(): void
    {
        $this->installer = \dirname(__DIR__, 3).'/site/installer';
        $this->createSandbox();
        $this->fakeTool('composer');
        $this->installDir = $this->sandbox.'/install';
        $this->origin = $this->createOrigin();
    }

    protected function tearDown(): void
    {
        $this->removeSandbox();
    }

    public function testScriptsAreSyntacticallyValid(): void
    {
        $root = \dirname(__DIR__, 3);
        foreach ([$this->installer, $root.'/bin/install.sh', $root.'/bin/uninstall.sh'] as $script) {
            $process = $this->runInSandbox([$this->realTool('bash'), '-n', $script]);
            $this->assertSame(0, $process->getExitCode(), $script.': '.$this->outputOf($process));
        }
    }

    public function testHelp(): void
    {
        $process = $this->install(['--help']);

        $this->assertSame(0, $process->getExitCode());
        $this->assertStringContainsString('--install-dir', $process->getOutput());
        $this->assertStringContainsString('--runtime', $process->getOutput());
    }

    public function testUnknownOptionIsRefused(): void
    {
        $process = $this->install(['--nope']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Unknown option --nope', $this->outputOf($process));
    }

    public function testNoRuntimeFoundPointsToThePrerequisites(): void
    {
        $this->fakeTool('orca');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('No agent runtime found', $this->outputOf($process));
        $this->assertStringContainsString(self::PREREQUISITES_URL, $this->outputOf($process));
        $this->assertDirectoryDoesNotExist($this->installDir);
    }

    public function testMissingComposerPointsToThePrerequisites(): void
    {
        unlink($this->sandbox.'/path/composer');
        $this->fakeTool('claude');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('Composer is required', $this->outputOf($process));
        $this->assertStringContainsString(self::PREREQUISITES_URL, $this->outputOf($process));
    }

    public function testTooOldPhpIsRefused(): void
    {
        $this->fakeTool('php', 'case "$*" in *version_compare*) exit 1 ;; *) echo 7.4.0 ;; esac');
        $this->fakeTool('claude');

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('PHP 7.4.0 is too old', $this->outputOf($process));
        $this->assertDirectoryDoesNotExist($this->installDir);
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
        $this->assertStringContainsString('agents will run headless', $this->outputOf($process));
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
        $this->assertDirectoryDoesNotExist($this->installDir);
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

    public function testDefaultInstallDirectoryIsUnderTheHome(): void
    {
        $this->fakeTool('claude');

        $process = $this->runInSandbox(
            [$this->realTool('bash'), $this->installer, '--no-interaction'],
            ['PABLO_REPO_URL' => $this->origin],
        );

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertFileExists($this->sandbox.'/home/.local/share/pablo/bin/install.sh');
    }

    public function testInstallDirectoryFromTheEnvironment(): void
    {
        $this->fakeTool('claude');

        $process = $this->runInSandbox(
            [$this->realTool('bash'), $this->installer, '--no-interaction'],
            ['PABLO_REPO_URL' => $this->origin, 'PABLO_INSTALL_DIR' => $this->sandbox.'/from-env'],
        );

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertFileExists($this->sandbox.'/from-env/bin/install.sh');
    }

    public function testRunningAgainUpdatesTheCheckout(): void
    {
        $this->fakeTool('claude');
        $this->assertSame(0, $this->install(['--no-interaction'])->getExitCode());

        file_put_contents($this->origin.'/NEW', "new\n");
        $this->git($this->origin, ['add', '.']);
        $this->git($this->origin, ['commit', '-q', '-m', 'new']);
        $process = $this->install(['--no-interaction']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('up to date', $this->outputOf($process));
        $this->assertFileExists($this->installDir.'/NEW');
    }

    public function testRefInstallsThatBranch(): void
    {
        $this->fakeTool('claude');
        $this->git($this->origin, ['checkout', '-q', '-b', 'next']);
        file_put_contents($this->origin.'/ON_NEXT', "next\n");
        $this->git($this->origin, ['add', '.']);
        $this->git($this->origin, ['commit', '-q', '-m', 'next']);
        $this->git($this->origin, ['checkout', '-q', 'main']);

        $process = $this->install(['--no-interaction', '--ref', 'next']);

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertFileExists($this->installDir.'/ON_NEXT');
    }

    public function testUnknownRefFails(): void
    {
        $this->fakeTool('claude');

        $process = $this->install(['--no-interaction', '--ref', 'nope']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString("Could not clone 'nope'", $this->outputOf($process));
    }

    public function testForeignNonEmptyDirectoryIsLeftAlone(): void
    {
        $this->fakeTool('claude');
        mkdir($this->installDir, 0o777, true);
        file_put_contents($this->installDir.'/mine.txt', "mine\n");

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('not a PABLO checkout', $this->outputOf($process));
        $this->assertFileExists($this->installDir.'/mine.txt');
        $this->assertFileDoesNotExist($this->installDir.'/bin/install.sh');
    }

    public function testInstallScriptFailureIsReported(): void
    {
        $this->fakeTool('claude');
        file_put_contents($this->origin.'/bin/install.sh', "#!/usr/bin/env bash\necho boom >&2\nexit 3\n");
        $this->git($this->origin, ['commit', '-q', '-am', 'broken']);

        $process = $this->install(['--no-interaction']);

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('bin/install.sh failed', $this->outputOf($process));
    }

    public function testInteractiveAnswers(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('claude');
        $this->fakeTool('orca');
        $this->fakeTool('openchamber');
        $custom = $this->sandbox.'/custom';
        // directory, runtime 2 = Claude Code, environment 2 = headless (OpenChamber is not offered), no dashboard, go
        $process = $this->interactive("{$custom}\n2\n2\nn\ny\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertFileExists($custom.'/bin/install.sh');
        $this->assertSame('--runtime claude-code --backend headless --without-web', $this->installArguments());
        $this->assertStringNotContainsString('1) OpenChamber', $this->outputOf($process));
    }

    public function testInteractiveMenuOffersOpenChamberWithOpenCode(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('orca');
        $this->fakeTool('openchamber');
        // environments: 1 Orca, 2 OpenChamber, 3 headless
        $process = $this->interactive("{$this->installDir}\n2\n\n\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertStringContainsString('2) OpenChamber', $this->outputOf($process));
        $this->assertSame('--runtime opencode --backend openchamber', $this->installArguments());
    }

    public function testInteractiveInvalidAnswerAsksAgain(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('claude');
        $process = $this->interactive("{$this->installDir}\n9\nabc\n2\n\n\n");

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

        $process = $this->interactive("{$this->installDir}\n\n\n\n");

        $this->assertSame(0, $process->getExitCode(), $this->outputOf($process));
        $this->assertSame('--runtime copilot --backend headless', $this->installArguments());
    }

    public function testInteractiveNoAbortsBeforeAnythingIsInstalled(): void
    {
        $this->fakeTool('claude');

        $process = $this->interactive("{$this->installDir}\n\nn\n");

        $this->assertNotSame(0, $process->getExitCode());
        $this->assertStringContainsString('nothing was installed', $this->outputOf($process));
        $this->assertDirectoryDoesNotExist($this->installDir);
    }

    /** @param list<string> $arguments */
    private function install(array $arguments): Process
    {
        if (!\in_array('--install-dir', $arguments, true)) {
            $arguments = ['--install-dir', $this->installDir, ...$arguments];
        }

        return $this->runInSandbox(
            [$this->realTool('bash'), $this->installer, ...$arguments],
            ['PABLO_REPO_URL' => $this->origin],
        );
    }

    private function interactive(string $answers): Process
    {
        file_put_contents($this->sandbox.'/answers', $answers);

        return $this->runInSandbox(
            [$this->realTool('bash'), $this->installer],
            ['PABLO_REPO_URL' => $this->origin, 'PABLO_INSTALLER_TTY' => $this->sandbox.'/answers'],
        );
    }

    private function installArguments(): string
    {
        $file = $this->sandbox.'/home/install-args';
        $this->assertFileExists($file, 'bin/install.sh was never run');

        return trim((string) file_get_contents($file));
    }

    private function createOrigin(): string
    {
        $origin = $this->sandbox.'/origin';
        mkdir($origin.'/bin', 0o777, true);
        mkdir($origin.'/app', 0o777, true);
        file_put_contents($origin.'/bin/install.sh', "#!/usr/bin/env bash\nprintf '%s\\n' \"\$*\" > \"\$HOME/install-args\"\n");
        file_put_contents($origin.'/bin/uninstall.sh', "#!/usr/bin/env bash\n");
        file_put_contents($origin.'/app/composer.json', "{}\n");
        $this->git($origin, ['init', '-q', '-b', 'main']);
        $this->git($origin, ['add', '.']);
        $this->git($origin, ['commit', '-q', '-m', 'init']);

        return $origin;
    }
}
