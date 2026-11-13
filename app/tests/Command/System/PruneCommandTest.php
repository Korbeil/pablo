<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\PruneCommand;
use Pablo\Config\GlobalConfig;
use Pablo\Housekeeping\Pruner;
use Pablo\Tests\Command\CommandTestBed;
use Pablo\Tests\Housekeeping\PabloSandbox;

final class PruneCommandTest extends CommandTestBed
{
    use PabloSandbox;

    private string $pablo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->pablo = $this->enterSandbox();
    }

    protected function tearDown(): void
    {
        $this->leaveSandbox();
        parent::tearDown();
    }

    private function command(): PruneCommand
    {
        return new PruneCommand(new Pruner(phar: false), new GlobalConfig());
    }

    public function testDryRunListsWithoutRemoving(): void
    {
        $old = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 40, str_repeat('x', 2048));

        $tester = $this->runCommand($this->command(), ['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString("would remove agent log {$old} (2.0 KB)", $tester->getDisplay());
        $this->assertStringContainsString('would free 2.0 KB', $tester->getDisplay());
        $this->assertFileExists($old);
    }

    public function testRemovesWithTheOlderThanOption(): void
    {
        $old = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 45);

        $this->assertStringContainsString('nothing to prune', $this->runCommand($this->command(), ['--older-than' => '60'])->getDisplay());
        $this->assertFileExists($old);

        $tester = $this->runCommand($this->command());
        $this->assertStringContainsString("removed agent log {$old}", $tester->getDisplay());
        $this->assertFileDoesNotExist($old);
    }

    public function testRefusesToKeepLessThanADay(): void
    {
        $young = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 10);

        $tester = $this->runCommand($this->command(), ['--older-than' => '0']);

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('--older-than must be a whole number of days, at least 1', $tester->getDisplay());
        $this->assertFileExists($young);
    }

    public function testKeepDaysComesFromTheConfig(): void
    {
        $log = $this->file($this->pablo.'/agents/logs/1000-task-analyst.log', 12);
        $base = (string) file_get_contents($this->configPath);

        file_put_contents($this->configPath, $base."\nprune:\n  keep_days: 15\n");
        $this->assertStringContainsString('nothing to prune', $this->runCommand($this->command())->getDisplay());
        $this->assertFileExists($log);

        file_put_contents($this->configPath, $base."\nprune:\n  keep_days: 10\n");
        $this->assertStringContainsString("removed agent log {$log}", $this->runCommand($this->command())->getDisplay());
        $this->assertFileDoesNotExist($log);
    }

    public function testAnInvalidKeepDaysIsAClearError(): void
    {
        file_put_contents($this->configPath, (string) file_get_contents($this->configPath)."\nprune:\n  keep_days: 0\n");

        $tester = $this->runCommand($this->command());

        $this->assertNotSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('prune.keep_days must be a whole number of days, at least 1', $tester->getDisplay());
    }

    public function testDryRunListsARotationWithoutTouchingTheLog(): void
    {
        $log = $this->file($this->pablo.'/logs/dispatch.err.log', 0, "boom\n");

        $tester = $this->runCommand($this->command(), ['--dry-run' => true]);

        $this->assertStringContainsString("would rotate service log {$log} into {$this->pablo}/logs/archive/dispatch.err.log.", $tester->getDisplay());
        $this->assertStringContainsString('would free 0 B', $tester->getDisplay());
        $this->assertSame("boom\n", file_get_contents($log));
    }

    public function testRejectsANonNumericAge(): void
    {
        $tester = $this->runCommand($this->command(), ['--older-than' => 'soon']);

        $this->assertNotSame(0, $tester->getStatusCode());
    }
}
