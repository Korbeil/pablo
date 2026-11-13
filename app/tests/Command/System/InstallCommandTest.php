<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Install\ConfigSeeder;
use Pablo\Install\ShellCompletion;
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
        $this->assertSame('headless', $this->configValue('agent_backend'), 'an explicit --backend headless is recorded');
        $this->assertFileExists($this->home.'/.pablo/runtime/claude/agents.json');
        $this->assertSame($this->home.'/.pablo/runtime/claude/agents/pablo-task-analyst.md', readlink($this->home.'/.claude/agents/pablo-task-analyst.md'));
        $this->assertSame($this->home.'/.pablo/runtime/claude/commands/pablo-commit-and-pr.md', readlink($this->home.'/.claude/commands/pablo-commit-and-pr.md'));
        $this->assertSame($this->home.'/.pablo/runtime/opencode/skills/pablo-task', readlink($this->home.'/.claude/skills/pablo-task'));
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
        $this->assertStringContainsString('OpenChamber only works with OpenCode', $tester->getDisplay());

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
        foreach (['pablo-dispatch.service', 'pablo-dispatch.timer', 'pablo-prune.service', 'pablo-prune.timer', 'pablo-web.service'] as $unit) {
            $this->assertFalse(is_link($units.'/'.$unit), "{$unit} is written, not linked");
            $this->assertStringContainsString('marker: '.$unit, (string) file_get_contents($units.'/'.$unit));
        }
        $this->assertStringContainsString('ExecStart=%h/.local/bin/pablo system:dispatch', (string) file_get_contents($units.'/pablo-dispatch.service'));
        $prune = (string) file_get_contents($units.'/pablo-prune.service');
        $this->assertStringContainsString('ExecStart=%h/.local/bin/pablo system:prune', $prune);
        $this->assertStringNotContainsString('@PATH@', $prune);
        $timer = (string) file_get_contents($units.'/pablo-prune.timer');
        $this->assertStringContainsString('OnCalendar=*-*-* 03:00:00', $timer);
        $this->assertStringContainsString('Persistent=true', $timer, 'a run missed while asleep happens after wake-up');
        $calls = $this->serviceCalls();
        $this->assertContains('systemctl --user enable --now pablo-prune.timer', $calls);
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
        $prune = (string) file_get_contents($this->home.'/Library/LaunchAgents/com.pablo.prune.plist');
        $this->assertStringContainsString('<string>system:prune</string>', $prune);
        $this->assertMatchesRegularExpression('#<key>StartCalendarInterval</key>\s*<dict>\s*<key>Hour</key>\s*<integer>3</integer>\s*<key>Minute</key>\s*<integer>0</integer>#', $prune);
        $this->assertStringContainsString($this->home.'/.pablo/logs/prune.log', $prune);
        $this->assertStringNotContainsString('@', str_replace(['@HOME', 'http'], '', $prune), 'every placeholder is replaced');
        $uid = posix_getuid();
        $this->assertContains("launchctl bootstrap gui/{$uid} {$this->home}/Library/LaunchAgents/com.pablo.prune.plist", $this->serviceCalls());
        $this->assertContains("launchctl bootstrap gui/{$uid} {$this->home}/Library/LaunchAgents/com.pablo.dispatch.plist", $this->serviceCalls());
    }

    public function testTheServicesPathIsComputedFromTheInstallingUsersPath(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('orca-ide');
        mkdir($this->sandbox.'/nvm/bin', 0o777, true);
        file_put_contents($this->sandbox.'/nvm/bin/node', "#!/bin/sh\n");
        chmod($this->sandbox.'/nvm/bin/node', 0o755);
        $this->setEnv('PATH', $this->sandbox.'/bin:'.$this->sandbox.'/nvm/bin');
        mkdir($this->home.'/.pablo', 0o777, true);
        file_put_contents($this->home.'/.pablo/config.yaml', "scheduler:\n  path: ['~/my tools/bin']\n");

        $tester = $this->install(['--runtime' => 'opencode', '--backend' => 'orca']);
        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $expected = "{$this->home}/my tools/bin:{$this->home}/.local/bin:{$this->home}/bin:{$this->sandbox}/bin:{$this->sandbox}/nvm/bin:/usr/local/sbin";
        $this->assertStringContainsString('services PATH: '.$expected, $tester->getDisplay());
        $units = $this->home.'/.config/systemd/user';
        foreach (['pablo-dispatch.service', 'pablo-web.service'] as $unit) {
            $content = (string) file_get_contents($units.'/'.$unit);
            $this->assertStringContainsString('Environment="PATH='.$expected, $content, $unit);
            $this->assertStringNotContainsString('@PATH@', $content, $unit);
            $this->assertStringNotContainsString('.nvm/versions', $content, "{$unit}: no machine-specific literal");
            $this->assertStringNotContainsString('orca-cli-shim', $content, $unit);
        }

        // Idempotent: the same PATH renders the same unit.
        $before = (string) file_get_contents($units.'/pablo-dispatch.service');
        $this->assertSame(0, $this->install(['--runtime' => 'opencode', '--backend' => 'orca'])->getStatusCode());
        $this->assertSame($before, file_get_contents($units.'/pablo-dispatch.service'));
    }

    public function testAnInvalidSchedulerPathIsRefusedBeforeTouchingAnything(): void
    {
        mkdir($this->home.'/.pablo', 0o777, true);
        file_put_contents($this->home.'/.pablo/config.yaml', "scheduler:\n  path: 42\n");

        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('scheduler.path must be a list of directories', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.local/bin/pablo');
    }

    public function testTheLaunchdPathIsComputedAndEscaped(): void
    {
        $this->fakeTool('opencode');
        $this->fakeTool('brew');
        mkdir($this->home.'/.pablo', 0o777, true);
        file_put_contents($this->home.'/.pablo/config.yaml', "scheduler:\n  path: ['/opt/a&b/bin']\n");

        $this->assertSame(0, $this->install(['--runtime' => 'opencode'], 'Darwin')->getStatusCode());

        $plist = (string) file_get_contents($this->home.'/Library/LaunchAgents/com.pablo.dispatch.plist');
        $this->assertStringContainsString("<string>/opt/a&amp;b/bin:{$this->home}/.local/bin:{$this->home}/bin:{$this->sandbox}/bin:/usr/local/bin:/usr/bin:/bin:/usr/sbin:/sbin</string>", $plist);
        $this->assertStringNotContainsString('@PATH@', $plist);
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
        $this->setEnv('SHELL', '/usr/bin/zsh');
        $this->assertSame(0, $this->install(['--runtime' => 'opencode'])->getStatusCode());
        $this->assertStringContainsString($this->home.'/.local/bin', (string) file_get_contents($this->home.'/.zshrc'));
        $this->assertFileDoesNotExist($this->home.'/.bashrc');

        $this->setEnv('SHELL', '/usr/bin/fish');
        $this->setEnv('PATH', $this->sandbox.'/bin:'.$this->home.'/.local/bin');
        $tester = $this->install(['--runtime' => 'opencode']);
        $this->assertStringContainsString('is already on your PATH', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.config/fish/conf.d/pablo.fish');
    }

    public function testTheShellCompletionIsInstalledUnlessOptedOut(): void
    {
        $bash = $this->home.'/.local/share/bash-completion/completions/pablo';

        $tester = $this->install(['--runtime' => 'opencode']);

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertStringContainsString("installed the bash completion: {$bash}", $tester->getDisplay());
        $this->assertStringContainsString(ShellCompletion::MARKER, (string) file_get_contents($bash));
        $this->assertStringContainsString('is up to date', $this->install(['--runtime' => 'opencode'])->getDisplay());

        $tester = $this->install(['--runtime' => 'opencode', '--without-completion' => true]);

        $this->assertStringContainsString("removed {$bash}", $tester->getDisplay());
        $this->assertFileDoesNotExist($bash);
        $this->assertSame('false', $this->configValue('shell_completion'));

        // Kept by a later install without the option (what `pablo update` runs).
        $this->assertStringContainsString('shell completion off', $this->install()->getDisplay());
        $this->assertFileDoesNotExist($bash);

        $this->install(['--with-completion' => true]);
        $this->assertSame('true', $this->configValue('shell_completion'));
        $this->assertFileExists($bash);
    }

    public function testBothCompletionOptionsAreRefusedBeforeTouchingAnything(): void
    {
        $tester = $this->install(['--with-completion' => true, '--without-completion' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('cannot be used together', $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.pablo/config.yaml');
    }

    public function testTheCompletionGoesToTheUsersShell(): void
    {
        $this->setEnv('SHELL', '/usr/bin/fish');
        $this->assertSame(0, $this->install(['--runtime' => 'opencode'])->getStatusCode());

        $this->assertFileExists($this->home.'/.config/fish/completions/pablo.fish');
        $this->assertFileDoesNotExist($this->home.'/.local/share/bash-completion/completions/pablo');
    }
}
