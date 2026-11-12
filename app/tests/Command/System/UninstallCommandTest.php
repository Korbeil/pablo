<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Tests\Install\InstallSandbox;
use PHPUnit\Framework\TestCase;

/**
 * `pablo system:uninstall` removes what system:install created (and a clone
 * install's links), and nothing else.
 */
final class UninstallCommandTest extends TestCase
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

    public function testRemovesWhatInstallCreatedAndKeepsTheData(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'claude-code'])->getStatusCode());
        $this->assertFileExists($this->home.'/.local/share/bash-completion/completions/pablo');
        file_put_contents($this->home.'/.bashrc', "# mine\n".(string) file_get_contents($this->home.'/.bashrc'));
        file_put_contents($this->home.'/.claude/agents/my-own.md', "mine\n");
        mkdir($this->home.'/.pablo/state', 0o777, true);
        file_put_contents($this->home.'/.local/bin/pablo.previous', "#!/bin/sh\necho 'pablo v0.9.0'\n");
        $this->runner->probeCalls = [];

        $tester = $this->uninstall();

        $this->assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $this->assertFileDoesNotExist($this->home.'/.claude/agents/pablo-task-analyst.md');
        $this->assertFileDoesNotExist($this->home.'/.claude/commands/pablo-commit-and-pr.md');
        $this->assertFileDoesNotExist($this->home.'/.claude/skills/pablo-task');
        $this->assertFileDoesNotExist($this->home.'/.local/bin/pablo');
        $this->assertFileDoesNotExist($this->home.'/.local/bin/pablo.previous', 'the binary kept for update --rollback goes too');
        $this->assertDirectoryDoesNotExist($this->home.'/.pablo/runtime');
        $this->assertFileDoesNotExist($this->home.'/.config/systemd/user/pablo-dispatch.timer');
        $this->assertContains('systemctl --user disable --now pablo-dispatch.timer', $this->serviceCalls());
        $this->assertFileDoesNotExist($this->home.'/.config/systemd/user/pablo-prune.timer');
        $this->assertFileDoesNotExist($this->home.'/.config/systemd/user/pablo-prune.service');
        $this->assertContains('systemctl --user disable --now pablo-prune.timer', $this->serviceCalls());
        $this->assertSame("# mine\n", file_get_contents($this->home.'/.bashrc'));
        $this->assertFileDoesNotExist($this->home.'/.local/share/bash-completion/completions/pablo');

        $this->assertSame("mine\n", file_get_contents($this->home.'/.claude/agents/my-own.md'));
        $this->assertFileExists($this->home.'/.pablo/config.yaml');
        $this->assertDirectoryExists($this->home.'/.pablo/state');
        $this->assertDirectoryExists($this->home.'/.pablo/projects');
    }

    public function testRemovesTheLinksOfACloneInstallEvenWhenTheCheckoutIsGone(): void
    {
        mkdir($this->home.'/.config/opencode/agents', 0o777, true);
        symlink($this->sandbox.'/deleted-clone/opencode/agents/task-analyst.md', $this->home.'/.config/opencode/agents/task-analyst.md');

        $this->assertSame(0, $this->uninstall()->getStatusCode());

        $this->assertFalse(is_link($this->home.'/.config/opencode/agents/task-analyst.md'));
    }

    public function testLeavesForeignFilesAndUnitsAlone(): void
    {
        mkdir($this->home.'/.config/opencode/agents', 0o777, true);
        mkdir($this->home.'/.config/systemd/user', 0o777, true);
        mkdir($this->home.'/.local/bin', 0o777, true);
        file_put_contents($this->home.'/.config/opencode/agents/task-analyst.md', "mine\n");
        file_put_contents($this->home.'/.config/systemd/user/pablo-web.service', "[Service]\n");
        file_put_contents($this->home.'/.config/systemd/user/pablo-prune.timer', "[Timer]\n");
        file_put_contents($this->home.'/.local/bin/pablo', "#!/bin/sh\necho not pablo\n");

        $this->assertSame(0, $this->uninstall()->getStatusCode());

        $this->assertFileExists($this->home.'/.config/opencode/agents/task-analyst.md');
        $this->assertFileExists($this->home.'/.config/systemd/user/pablo-web.service');
        $this->assertFileExists($this->home.'/.config/systemd/user/pablo-prune.timer');
        $this->assertFileExists($this->home.'/.local/bin/pablo');
    }

    public function testRemovesTheLaunchdAgentsOnMacos(): void
    {
        $this->assertSame(0, $this->install(['--runtime' => 'opencode'], 'Darwin')->getStatusCode());

        $this->assertSame(0, $this->uninstall('Darwin')->getStatusCode());

        $this->assertFileDoesNotExist($this->home.'/Library/LaunchAgents/com.pablo.dispatch.plist');
        $this->assertFileDoesNotExist($this->home.'/Library/LaunchAgents/com.pablo.web.plist');
        $this->assertFileDoesNotExist($this->home.'/Library/LaunchAgents/com.pablo.prune.plist');
    }
}
