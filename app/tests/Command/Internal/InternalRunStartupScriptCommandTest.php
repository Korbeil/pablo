<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Internal;

use Pablo\Command\Internal\InternalRunStartupScriptCommand;
use Pablo\Tests\Command\CommandTestBed;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The headless backend runs a harmless script for real; PABLO_SHIM points
 * the watcher's detached self-reinvocation at a script that only records its
 * argv, and PABLO_AGENTS_DIR keeps pidfiles and logs inside the tmp dir.
 */
final class InternalRunStartupScriptCommandTest extends CommandTestBed
{
    private string $agentsDir;
    private string $shimArgs;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agentsDir = $this->tmp.'/agents';
        mkdir($this->agentsDir, 0o777, true);
        $this->shimArgs = $this->tmp.'/shim-args';
        $shim = $this->tmp.'/shim';
        file_put_contents($shim, "#!/bin/sh\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($this->shimArgs)."\n");
        chmod($shim, 0o755);
        putenv('PABLO_AGENTS_DIR='.$this->agentsDir);
        putenv('PABLO_SHIM='.$shim);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENTS_DIR');
        putenv('PABLO_SHIM');
        parent::tearDown();
        \Pablo\Support\Files::remove($this->tmp);
    }

    public function testRunsTheScriptOnceFromTheWorktreeAndWatchesIt(): void
    {
        $ranIn = $this->tmp.'/ran-in';
        $script = $this->tmp.'/startup.sh';
        file_put_contents($script, 'echo "$PWD" >> '.escapeshellarg($ranIn)."\n");
        chdir($this->tmp); // the caller's cwd is never the worktree's

        $tester = new CommandTester(new InternalRunStartupScriptCommand($this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents));
        $tester->execute([
            '--backend' => 'headless',
            '--worktree' => $this->wt,
            '--script' => $script,
            '--project' => 'wallet-kit',
            '--branch' => 'wk-45',
        ]);
        $this->assertSame(0, $tester->getStatusCode());

        $this->waitFor($ranIn);
        $this->waitFor($this->shimArgs);
        $this->assertSame(realpath($this->wt)."\n", file_get_contents($ranIn));

        $argv = file((string) $this->shimArgs, \FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertSame('internal:watch-agent', $argv[0] ?? null);
        $this->assertSame('headless', $argv[array_search('--backend', $argv, true) + 1]);
        $this->assertSame('startup-script', $argv[array_search('--agent', $argv, true) + 1]);
        $this->assertSame('wk-45', $argv[array_search('--branch', $argv, true) + 1]);
        $this->assertMatchesRegularExpression('/^pid:\d+$/', $argv[array_search('--handle', $argv, true) + 1]);
    }

    private function waitFor(string $file): void
    {
        $deadline = microtime(true) + 10;
        while (!is_file($file) && microtime(true) < $deadline) {
            usleep(50_000);
        }
        usleep(100_000); // let the writer finish
        $this->assertFileExists($file);
    }
}
