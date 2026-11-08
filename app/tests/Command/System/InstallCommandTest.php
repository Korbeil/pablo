<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Install\ConfigSeeder;
use Pablo\Tests\Install\InstallSandbox;
use PHPUnit\Framework\TestCase;

/**
 * `pablo system:install` in a throwaway HOME: the config it records
 * (--runtime / --backend), the files it renders and links, the services it
 * writes, that running it again changes nothing, that it never touches a
 * file that is not PABLO's, and that it takes over a clone install.
 */
final class InstallCommandTest extends TestCase
{
    use InstallSandbox;

    protected function setUp(): void
    {
        $this->createInstallSandbox();
    }

    protected function tearDown(): void
    {
        $this->removeInstallSandbox();
    }

    public function testRuntimeAndBackendAreRecordedOnAFirstInstall(): void
    {
        $tester = $this->install(['--runtime' => 'opencode', '--backend' => 'orca']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('opencode', $this->configValue('agent_runtime'));
        $this->assertSame('orca', $this->configValue('agent_backend'));
        $this->assertSame(ConfigSeeder::value(ConfigSeeder::example(), 'default_model'), $this->configValue('default_model'));
        $this->assertDirectoryExists($this->home.'/.pablo/projects');

        // Rendered into ~/.pablo/runtime, linked from ~/.config/opencode.
        $agent = $this->home.'/.config/opencode/agents/task-analyst.md';
        $this->assertTrue(is_link($agent));
        $this->assertSame($this->home.'/.pablo/runtime/opencode/agents/task-analyst.md', readlink($agent));
        $this->assertStringContainsString('---', (string) file_get_contents($agent));
        $this->assertTrue(is_link($this->home.'/.config/opencode/commands/pablo-commit-and-pr.md'));
        $this->assertFileExists($this->home.'/.config/opencode/skill/pablo-task/SKILL.md');

        $shim = (string) file_get_contents($this->home.'/.local/bin/pablo');
        $this->assertStringContainsString("exec php '".\dirname(__DIR__, 4)."/app/bin/pablo'", $shim, 'from a checkout, the command is a shim');
        $this->assertTrue(is_executable($this->home.'/.local/bin/pablo'));
    }

    public function testClaudeCodeGetsAClaudeModelAndItsLinks(): void
    {
        $tester = $this->install(['--runtime' => 'claude-code', '--backend' => 'headless']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('claude-code', $this->configValue('agent_runtime'));
        $this->assertSame('sonnet', $this->configValue('default_model'));
        $this->assertSame('orca', $this->configValue('agent_backend'), 'headless is Orca falling back by itself');
        $this->assertFileExists($this->home.'/.pablo/runtime/claude/agents.json');
        $this->assertSame($this->home.'/.pablo/runtime/claude/agents/pablo-task-analyst.md', readlink($this->home.'/.claude/agents/pablo-task-analyst.md'));
        $this->assertSame($this->home.'/.pablo/runtime/claude/commands/pablo-commit-and-pr.md', readlink($this->home.'/.claude/commands/pablo-commit-and-pr.md'));
        $this->assertSame($this->home.'/.pablo/runtime/opencode/skills/pablo-task', readlink($this->home.'/.claude/skills/pablo-task'));
    }

    public function testClaudeCodeTrustsTheWorktreesFolder(): void
    {
        file_put_contents($this->home.'/.claude.json', '{"projects":{}}');
        putenv('PABLO_CLAUDE_JSON');

        try {
            $tester = $this->install(['--runtime' => 'claude-code']);
        } finally {
            putenv('PABLO_CLAUDE_JSON=/nonexistent/pablo-tests/.claude.json');
        }

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $config = json_decode((string) file_get_contents($this->home.'/.claude.json'), true);
        $this->assertSame(['hasTrustDialogAccepted' => true], $config['projects'][$this->home.'/.pablo/worktrees'] ?? null);
        $this->assertSame(0, $this->install(['--runtime' => 'claude-code'])->getStatusCode(), 'idempotent');
    }

    public function testCopilotGetsACopilotModelAndItsLinks(): void
    {
        $tester = $this->install(['--runtime' => 'copilot']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('copilot', $this->configValue('agent_runtime'));
        $this->assertSame('claude-sonnet-5.5', $this->configValue('default_model'));
        $this->assertSame($this->home.'/.pablo/runtime/copilot/agents/pablo-task-analyst.agent.md', readlink($this->home.'/.copilot/agents/pablo-task-analyst.agent.md'));
        $this->assertFileExists($this->home.'/.copilot/skills/pablo-commit-and-pr/SKILL.md');
        $this->assertFileExists($this->home.'/.copilot/skills/pablo-task/SKILL.md');
    }

    public function testSwitchingRuntimeRewritesTheKeysInPlace(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'claude-code'])->getStatusCode());
        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('opencode', $this->configValue('agent_runtime'));
        $this->assertSame(ConfigSeeder::value(ConfigSeeder::example(), 'default_model'), $this->configValue('default_model'));
        $config = (string) file_get_contents($this->home.'/.pablo/config.yaml');
        $this->assertSame(1, preg_match_all('/^agent_runtime:/m', $config));
        $this->assertSame(1, preg_match_all('/^default_model:/m', $config));
    }

    public function testChoosingTheSameRuntimeKeepsTheModelYouSet(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'claude-code'])->getStatusCode());
        ConfigSeeder::set($this->home.'/.pablo/config.yaml', 'default_model', 'opus');

        $tester = $this->install(['--runtime' => 'claude-code']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('opus', $this->configValue('default_model'));
    }

    public function testWithoutOptionsAnExistingConfigIsLeftAlone(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'copilot', '--backend' => 'orca'])->getStatusCode());
        $before = (string) file_get_contents($this->home.'/.pablo/config.yaml');

        $tester = $this->install();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($before, (string) file_get_contents($this->home.'/.pablo/config.yaml'));
        $this->assertStringContainsString('agent runtime: copilot', $tester->getDisplay());
    }

    public function testRunningTwiceChangesNothing(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'claude-code', '--backend' => 'orca'])->getStatusCode());
        $config = (string) file_get_contents($this->home.'/.pablo/config.yaml');
        $bashrc = (string) file_get_contents($this->home.'/.bashrc');

        $tester = $this->install(['--runtime' => 'claude-code', '--backend' => 'orca']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($config, (string) file_get_contents($this->home.'/.pablo/config.yaml'));
        $this->assertSame($bashrc, (string) file_get_contents($this->home.'/.bashrc'), 'the PATH block is added once');
        $this->assertSame(1, substr_count($bashrc, '# >>> pablo >>>'));
    }

    public function testAFirstInstallStillPicksTheToolsItFinds(): void
    {
        $this->fakeTool('claude');
        $this->fakeTool('openchamber');

        $tester = $this->install();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('claude-code', $this->configValue('agent_runtime'));
        $this->assertNull($this->configValue('agent_backend'), 'OpenChamber cannot run Claude Code');
    }

    public function testOpenChamberIsPickedWithOpenCodeWhenOrcaIsMissing(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('openchamber');

        $this->assertSame(0, $this->install()->getStatusCode());

        $this->assertNull($this->configValue('agent_runtime'), 'OpenCode is the default');
        $this->assertSame('openchamber', $this->configValue('agent_backend'));
    }

    public function testGnomesScreenReaderDoesNotCountAsOrca(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('openchamber');
        file_put_contents($this->sandbox.'/bin/orca', "#!/usr/bin/python3\nimport orca\n");
        chmod($this->sandbox.'/bin/orca', 0o755);

        $this->assertSame(0, $this->install()->getStatusCode());

        $this->assertSame('openchamber', $this->configValue('agent_backend'), 'no Orca here: OpenChamber is picked');
    }

    public function testOpenChamberWithAnotherRuntimeIsRefusedBeforeTouchingAnything(): void
    {
        $tester = $this->install(['--runtime' => 'claude-code', '--backend' => 'openchamber']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.pablo/config.yaml');
        $this->assertFileDoesNotExist($this->home.'/.local/bin/pablo');
    }

    public function testAKeptOpenChamberBackendBlocksAnotherRuntime(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'opencode', '--backend' => 'openchamber'])->getStatusCode());

        $tester = $this->install(['--runtime' => 'copilot']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('agent_backend: openchamber only works with OpenCode', $tester->getDisplay());

        $this->assertSame(0, $this->install(['--runtime' => 'copilot', '--backend' => 'orca'])->getStatusCode());
        $this->assertSame('orca', $this->configValue('agent_backend'));
    }

    public function testBadOptionsAreRefused(): void
    {
        foreach ([['--runtime' => 'vim'], ['--backend' => 'vim']] as $input) {
            $tester = $this->install($input);

            $this->assertSame(1, $tester->getStatusCode(), (string) json_encode($input));
            $this->assertStringContainsString('unknown --', $tester->getDisplay());
        }
        $this->assertFileDoesNotExist($this->home.'/.pablo/config.yaml');
    }

    public function testAnUnsupportedPlatformIsRefusedBeforeTouchingAnything(): void
    {
        $tester = $this->install([], 'Windows');

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('unsupported platform: Windows', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.pablo/config.yaml');
    }

    public function testSystemdUnitsAreWrittenAndTheDashboardInstalledUnlessOptedOut(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'opencode'])->getStatusCode());

        $units = $this->home.'/.config/systemd/user';
        foreach (['pablo-dispatch.service', 'pablo-dispatch.timer', 'pablo-web.service'] as $unit) {
            $this->assertFalse(is_link($units.'/'.$unit), "{$unit} is written, not linked");
            $this->assertStringContainsString('marker: '.$unit, (string) file_get_contents($units.'/'.$unit));
        }
        $this->assertStringContainsString('ExecStart=%h/.local/bin/pablo system:dispatch', (string) file_get_contents($units.'/pablo-dispatch.service'));
        $calls = $this->serviceCalls();
        $this->assertContains('loginctl enable-linger '.(posix_getpwuid(posix_geteuid())['name'] ?? ''), $calls);
        $this->assertContains('systemctl --user enable --now pablo-dispatch.timer', $calls);
        $this->assertContains('systemctl --user enable --now pablo-web.service', $calls);
        $this->assertContains('systemctl --user restart pablo-web.service', $calls, 'a new binary must replace the running dashboard');

        $this->runner->probeCalls = [];
        $this->assertSame(0, $this->install(['--runtime' => 'opencode', '--without-web' => true])->getStatusCode());

        $this->assertContains('systemctl --user disable --now pablo-web.service', $this->serviceCalls());
        $this->assertNotContains('systemctl --user enable --now pablo-web.service', $this->serviceCalls());
        $this->assertFileDoesNotExist($units.'/pablo-web.service');
    }

    public function testAFailingSystemctlFailsTheInstall(): void
    {
        $this->runner->onProbe = static fn (array $argv): \Pablo\Support\ProbeResult => new \Pablo\Support\ProbeResult(
            \in_array('enable', $argv, true) ? 1 : 0,
            'Failed to connect to bus',
        );

        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('systemctl --user enable --now pablo-dispatch.timer failed: Failed to connect to bus', $tester->getDisplay());
    }

    public function testLaunchdAgentsAreRenderedForTheBinary(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'opencode'], 'Darwin')->getStatusCode());

        $plist = (string) file_get_contents($this->home.'/Library/LaunchAgents/com.pablo.dispatch.plist');
        $this->assertStringContainsString('<string>'.$this->home.'/.local/bin/pablo</string>', $plist);
        $this->assertStringContainsString($this->home.'/.pablo/logs/dispatch.log', $plist);
        $this->assertStringNotContainsString('@', str_replace(['@HOME', 'http'], '', $plist), 'every placeholder is replaced');
        $this->assertFileExists($this->home.'/Library/LaunchAgents/com.pablo.web.plist');
        $uid = posix_getuid();
        $this->assertContains("launchctl bootstrap gui/{$uid} {$this->home}/Library/LaunchAgents/com.pablo.dispatch.plist", $this->serviceCalls());
    }

    public function testAForeignPlistIsNeverOverwritten(): void
    {
        mkdir($this->home.'/Library/LaunchAgents', 0o777, true);
        file_put_contents($this->home.'/Library/LaunchAgents/com.pablo.dispatch.plist', "mine\n");

        $tester = $this->install(['--runtime' => 'opencode'], 'Darwin');

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('was not written by PABLO', $tester->getDisplay());
        $this->assertSame("mine\n", file_get_contents($this->home.'/Library/LaunchAgents/com.pablo.dispatch.plist'));
    }

    public function testAForeignAgentFileIsNeverOverwritten(): void
    {
        mkdir($this->home.'/.config/opencode/agents', 0o777, true);
        file_put_contents($this->home.'/.config/opencode/agents/task-analyst.md', "mine\n");

        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('refusing to overwrite', $tester->getDisplay());
        $this->assertSame("mine\n", file_get_contents($this->home.'/.config/opencode/agents/task-analyst.md'));
    }

    public function testASymlinkOfTheUsersIsNeverReplaced(): void
    {
        mkdir($this->home.'/.config/opencode/agents', 0o777, true);
        file_put_contents($this->sandbox.'/my-agent.md', "mine\n");
        symlink($this->sandbox.'/my-agent.md', $this->home.'/.config/opencode/agents/task-analyst.md');

        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertSame($this->sandbox.'/my-agent.md', readlink($this->home.'/.config/opencode/agents/task-analyst.md'));
    }

    public function testACloneInstallIsTakenOver(): void
    {
        // What bin/install.sh left behind: links into the checkout, unit
        // symlinks, the php shim and ~/.pablo/claude/agents.json.
        $clone = $this->sandbox.'/clone';
        foreach (['app/bin', 'opencode/agents', 'opencode/commands', 'opencode/skills/pablo-task', 'claude/agents', 'systemd'] as $dir) {
            mkdir($clone.'/'.$dir, 0o777, true);
        }
        file_put_contents($clone.'/app/bin/pablo', "<?php\n");
        file_put_contents($clone.'/opencode/agents/task-analyst.md', "old agent\n");
        file_put_contents($clone.'/opencode/commands/pablo-commit-and-pr.md', "old command\n");
        file_put_contents($clone.'/systemd/pablo-dispatch.service', "old unit\n");
        mkdir($this->home.'/.config/opencode/agents', 0o777, true);
        mkdir($this->home.'/.config/opencode/commands', 0o777, true);
        mkdir($this->home.'/.config/opencode/skill', 0o777, true);
        mkdir($this->home.'/.config/systemd/user', 0o777, true);
        mkdir($this->home.'/.local/bin', 0o777, true);
        mkdir($this->home.'/.pablo/claude', 0o777, true);
        symlink($clone.'/opencode/agents/task-analyst.md', $this->home.'/.config/opencode/agents/task-analyst.md');
        symlink($clone.'/opencode/commands/pablo-commit-and-pr.md', $this->home.'/.config/opencode/commands/pablo-commit-and-pr.md');
        symlink($clone.'/opencode/skills/pablo-task', $this->home.'/.config/opencode/skill/pablo-task');
        symlink($clone.'/systemd/pablo-dispatch.service', $this->home.'/.config/systemd/user/pablo-dispatch.service');
        file_put_contents($this->home.'/.local/bin/pablo', "#!/usr/bin/env bash\nexec php '{$clone}/app/bin/pablo' \"\$@\"\n");
        file_put_contents($this->home.'/.pablo/claude/agents.json', "{}\n");

        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame($this->home.'/.pablo/runtime/opencode/agents/task-analyst.md', readlink($this->home.'/.config/opencode/agents/task-analyst.md'));
        $this->assertSame($this->home.'/.pablo/runtime/opencode/commands/pablo-commit-and-pr.md', readlink($this->home.'/.config/opencode/commands/pablo-commit-and-pr.md'));
        $this->assertSame($this->home.'/.pablo/runtime/opencode/skills/pablo-task', readlink($this->home.'/.config/opencode/skill/pablo-task'));
        $unit = $this->home.'/.config/systemd/user/pablo-dispatch.service';
        $this->assertFalse(is_link($unit));
        $this->assertStringContainsString('marker: pablo-dispatch.service', (string) file_get_contents($unit));
        $this->assertStringNotContainsString($clone, (string) file_get_contents($this->home.'/.local/bin/pablo'));
        $this->assertDirectoryDoesNotExist($this->home.'/.pablo/claude');

        // The checkout itself is never written to.
        $this->assertSame("old agent\n", file_get_contents($clone.'/opencode/agents/task-analyst.md'));
        $this->assertSame("old unit\n", file_get_contents($clone.'/systemd/pablo-dispatch.service'));
    }

    public function testThePortIsRecordedForTheDashboardAndShownInTheNextSteps(): void
    {
        $tester = $this->install(['--runtime' => 'opencode', '--port' => '8400']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertSame('8400', $this->configValue('dashboard_port'));
        $this->assertStringContainsString('watch it on http://127.0.0.1:8400', $tester->getDisplay());
        $this->assertStringContainsString('ExecStart=%h/.local/bin/pablo web', (string) file_get_contents($this->home.'/.config/systemd/user/pablo-web.service'), 'the unit reads the port from the config');

        $this->assertSame(0, $this->install(['--runtime' => 'opencode'])->getStatusCode());
        $this->assertSame('8400', $this->configValue('dashboard_port'), 'kept by a later install');
    }

    public function testAnInvalidPortIsRefusedBeforeTouchingAnything(): void
    {
        $tester = $this->install(['--port' => '70000']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('invalid --port 70000', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.pablo/config.yaml');
    }

    public function testPathIsAddedForTheUsersShellOnlyWhenMissing(): void
    {
        putenv('SHELL=/usr/bin/zsh');
        $this->assertSame(0, $this->install(['--runtime' => 'opencode'])->getStatusCode());
        $this->assertStringContainsString($this->home.'/.local/bin', (string) file_get_contents($this->home.'/.zshrc'));
        $this->assertFileDoesNotExist($this->home.'/.bashrc');

        putenv('SHELL=/usr/bin/fish');
        putenv('PATH='.$this->sandbox.'/bin:'.$this->home.'/.local/bin');
        $tester = $this->install(['--runtime' => 'opencode']);
        $this->assertStringContainsString('is already on your PATH', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.config/fish/conf.d/pablo.fish');
    }
}
