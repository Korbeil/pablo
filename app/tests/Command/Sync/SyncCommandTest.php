<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\Sync;

use Pablo\Command\Sync\SyncCommand;
use Pablo\Provider\Git\Sync;
use Pablo\Provider\Git\SyncReport;
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
        $this->git->syncReport = new SyncReport(worktree: $this->wt, branch: 'wk-45', action: 'would-sync', behind: 2, ahead: 1);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_LOGS_DIR');
        putenv('PABLO_STAMPS_DIR');
        parent::tearDown();
    }

    private function command(): SyncCommand
    {
        $sync = new Sync($this->git, $this->gh, $this->repoSlug, $this->time, $this->runtimes);

        return new SyncCommand($sync, new \Pablo\Dispatch\DispatchHealth(), $this->store, $this->projectsLoader, $this->agents);
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

    public function testJsonReportsPerWorktree(): void
    {
        $tester = $this->runCommand($this->command(), ['--format' => 'json']);

        $this->assertSame(0, $tester->getStatusCode());
        $payload = json_decode($tester->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);
        $this->assertIsArray($payload);
        $project = $payload['projects'][0];
        $this->assertSame('wallet-kit', $project['project']);
        $this->assertSame('rebase', $project['strategy']);
        $this->assertFalse($project['applied']);
        $report = $project['reports'][0];
        $this->assertSame('wk-45', $report['branch']);
        $this->assertSame($this->wt, $report['worktree']);
        $this->assertSame('would-sync', $report['action']);
        $this->assertSame(2, $report['behind']);
        $this->assertSame(1, $report['ahead']);
        $this->assertFalse($this->git->syncCalls[0]['apply']);
    }

    public function testJsonDryRunIsNotApplied(): void
    {
        $this->enableAutoApply();

        $payload = json_decode($this->runCommand($this->command(), ['--format' => 'json', '--dry-run' => true])->getDisplay(), true, 512, \JSON_THROW_ON_ERROR);

        $this->assertIsArray($payload);
        $this->assertFalse($payload['projects'][0]['applied']);
        $this->assertSame([false], $this->applyFlags());
    }

    public function testTable(): void
    {
        $tester = $this->runCommand($this->command());

        $this->assertStringContainsString('# wallet-kit', $tester->getDisplay());
        $this->assertStringContainsString('would-sync (behind 2, ahead 1)', $tester->getDisplay());
    }
}
