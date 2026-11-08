<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\AbstractAgentLauncher;
use PHPUnit\Framework\TestCase;

/**
 * The detached shell every headless agent runs in, executed for real.
 */
final class AbstractAgentLauncherTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir().'/pablo-detach-'.bin2hex(random_bytes(4));
        mkdir($this->tmp.'/work tree', 0o777, true);
    }

    protected function tearDown(): void
    {
        exec('rm -rf '.escapeshellarg($this->tmp));
    }

    /**
     * The headless fallback used to run `exec cd <worktree>; …`: bash cannot
     * exec a builtin, so it exited before the agent CLI or even its log.
     */
    public function testTheCommandRunsInTheWorktreeAndLogsItsOutput(): void
    {
        $log = $this->tmp.'/agent.log';

        $this->runDetached(AbstractAgentLauncher::inWorktree($this->tmp.'/work tree', 'sh -c '.escapeshellarg('pwd; echo agent ran'), $log));

        $this->assertSame($this->tmp."/work tree\nagent ran\n", file_get_contents($log));
    }

    public function testAMissingWorktreeIsLoggedInsteadOfVanishing(): void
    {
        $log = $this->tmp.'/agent.log';

        $this->runDetached(AbstractAgentLauncher::inWorktree($this->tmp.'/gone', 'echo should not run', $log));

        $this->assertStringContainsString('gone', (string) file_get_contents($log));
        $this->assertStringNotContainsString('should not run', (string) file_get_contents($log));
    }

    /** Run a detached script and wait for it to finish. */
    private function runDetached(string $script): void
    {
        $pid = (int) trim((string) shell_exec(AbstractAgentLauncher::detach($script)));
        $this->assertGreaterThan(0, $pid);
        $deadline = microtime(true) + 10;
        while (@posix_kill($pid, 0) && microtime(true) < $deadline) {
            usleep(50_000);
        }
    }
}
