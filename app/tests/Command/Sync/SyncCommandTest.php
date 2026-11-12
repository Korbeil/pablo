<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Sync;

use Pablo\Command\Sync\SyncCommand;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\WorktreeRef;
use Pablo\Tests\Command\CommandTestBed;

final class SyncCommandTest extends CommandTestBed
{
    protected function setUp(): void
    {
        parent::setUp();
        putenv('PABLO_LOGS_DIR='.$this->tmp.'/logs');
        putenv('PABLO_STAMPS_DIR='.$this->tmp.'/stamps');
        $this->git->worktrees = [new WorktreeRef($this->wt, 'wk-45')];
    }

    protected function tearDown(): void
    {
        putenv('PABLO_LOGS_DIR');
        putenv('PABLO_STAMPS_DIR');
        parent::tearDown();
    }

    private function command(): SyncCommand
    {
        $sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->runner, $this->time);

        return new SyncCommand($sync, new \Pablo\Dispatch\DispatchHealth(), $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
    }

    private function enableAutoApply(): void
    {
        $file = $this->projectsDir.'/wallet-kit.yaml';
        file_put_contents($file, str_replace('auto_apply: false', 'auto_apply: true', (string) file_get_contents($file)));
    }

    /** @return list<mixed> */
    private function applyFlags(): array
    {
        return array_column($this->git->syncCalls, 'apply');
    }

    public function testDefaultsToDryRunWithoutAutoApply(): void
    {
        $this->runCommand($this->command());

        $this->assertSame([false], $this->applyFlags());
    }

    public function testDefaultsToProjectAutoApply(): void
    {
        $this->enableAutoApply();
        $this->runCommand($this->command());

        $this->assertSame([true], $this->applyFlags());
    }

    public function testApplyForcesApply(): void
    {
        $tester = $this->runCommand($this->command(), ['--apply' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([true], $this->applyFlags());
    }

    public function testDryRunOverridesAutoApply(): void
    {
        $this->enableAutoApply();

        $tester = $this->runCommand($this->command(), ['--dry-run' => true]);

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertSame([false], $this->applyFlags());
        $this->assertSame([], $this->git->gitCalls);
    }

    public function testApplyAndDryRunAreMutuallyExclusive(): void
    {
        $tester = $this->runCommand($this->command(), ['--apply' => true, '--dry-run' => true]);

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('mutually exclusive', $tester->getDisplay());
        $this->assertSame([], $this->git->syncCalls);
    }

    public function testARunClearsTheDispatcherFailureStreakUnlessDryRun(): void
    {
        $health = new \Pablo\Dispatch\DispatchHealth();
        $health->recordFailure('wallet-kit', 'sync', microtime(true), 'boom');

        $this->runCommand($this->command(), ['--dry-run' => true]);
        $this->assertTrue($health->read('wallet-kit', 'sync')?->failing());

        $this->runCommand($this->command());
        $this->assertFalse($health->read('wallet-kit', 'sync')->failing());
    }
}
