<?php

declare(strict_types=1);

namespace Pablo\Tests\Command\System;

use Pablo\Command\System\RepairMergeFlagsCommand;
use Pablo\Tests\Command\CommandTestBed;

final class RepairMergeFlagsCommandTest extends CommandTestBed
{
    private string $analyticsDir;

    private string $prevAnalyticsDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->analyticsDir = $this->tmp.'/analytics';
        mkdir($this->analyticsDir.'/wallet-kit', 0o777, true);
        $this->prevAnalyticsDir = (string) getenv('PABLO_ANALYTICS_DIR');
        putenv('PABLO_ANALYTICS_DIR='.$this->analyticsDir);
    }

    protected function tearDown(): void
    {
        if ('' !== $this->prevAnalyticsDir) {
            putenv('PABLO_ANALYTICS_DIR='.$this->prevAnalyticsDir);
        } else {
            putenv('PABLO_ANALYTICS_DIR');
        }
        parent::tearDown();
    }

    /** @param array<string, mixed> $extra */
    private function writeEvent(string $month, array $extra): string
    {
        $line = json_encode(['ts' => '2026-08-23T12:00:39+00:00', 'project' => 'wallet-kit', ...$extra], \JSON_UNESCAPED_SLASHES);
        \assert(false !== $line);
        file_put_contents($this->analyticsDir."/wallet-kit/{$month}.jsonl", $line."\n", \FILE_APPEND);

        return $line;
    }

    /** @return list<array<string, mixed>> */
    private function readEvents(string $month): array
    {
        $raw = file_get_contents($this->analyticsDir."/wallet-kit/{$month}.jsonl");
        $this->assertNotFalse($raw);

        return array_map(
            static fn (string $l): array => json_decode($l, true, flags: \JSON_THROW_ON_ERROR),
            array_values(array_filter(explode("\n", $raw), static fn (string $l) => '' !== $l)),
        );
    }

    public function testRepairsMergedFalseWhenPrIsMerged(): void
    {
        // Pre-fix history: merged PR recorded as not merged.
        $this->writeEvent('2026-08', ['type' => 'task_closed', 'branch' => 'wk-45', 'merged' => false, 'pr_number' => 7]);
        $this->writeEvent('2026-08', ['type' => 'task_closed', 'branch' => 'wk-46', 'merged' => true, 'pr_number' => 8]);
        $this->writeEvent('2026-08', ['type' => 'state_entered', 'branch' => 'wk-45', 'from' => 'draft', 'to' => 'ci-red']);
        $this->writeEvent('2026-08', ['type' => 'task_closed', 'branch' => 'wk-47', 'merged' => false, 'pr_number' => null]);
        // Genuinely unmerged PR must stay untouched.
        $this->gh->isMergedOverride = static fn (string $slug, int $pr): bool => 7 === $pr;

        $command = new RepairMergeFlagsCommand($this->gh, $this->repoSlug, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
        $tester = $this->runCommand($command);

        $tester->assertCommandIsSuccessful();
        $events = $this->readEvents('2026-08');
        $this->assertCount(4, $events);
        // The pre-fix event was flipped to merged=true.
        $this->assertTrue($events[0]['merged']);
        $this->assertSame(7, $events[0]['pr_number']);
        // The already-true event is untouched.
        $this->assertTrue($events[1]['merged']);
        // The non-PR event and the no-PR close are untouched.
        $this->assertSame('state_entered', $events[2]['type']);
        $this->assertFalse($events[3]['merged']);
        $this->assertNull($events[3]['pr_number']);
        $this->assertStringContainsString('wallet-kit: 1 repaired', $tester->getDisplay());
    }

    public function testDryRunLeavesFilesUntouched(): void
    {
        $this->writeEvent('2026-07', ['type' => 'task_closed', 'branch' => 'wk-45', 'merged' => false, 'pr_number' => 7]);
        $this->gh->isMergedOverride = static fn (): bool => true;

        $command = new RepairMergeFlagsCommand($this->gh, $this->repoSlug, $this->store, $this->projectsLoader, $this->agentLaunchers, $this->agents);
        $tester = $this->runCommand($command, ['--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        foreach ($this->readEvents('2026-07') as $event) {
            $this->assertFalse($event['merged']);
        }
        $this->assertStringContainsString('[dry run] would repair 1 event(s)', $tester->getDisplay());
    }
}
