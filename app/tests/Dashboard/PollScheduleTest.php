<?php

declare(strict_types=1);

namespace Pablo\Tests\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Dashboard\PollSchedule;
use Pablo\Dispatch\Dispatch;
use PHPUnit\Framework\TestCase;

final class PollScheduleTest extends TestCase
{
    private string $stamps;
    private PollSchedule $schedule;

    protected function setUp(): void
    {
        $this->stamps = sys_get_temp_dir().'/pablo-poll-'.uniqid();
        mkdir($this->stamps, 0o777, true);
        putenv('PABLO_STAMPS_DIR='.$this->stamps);
        $this->schedule = new PollSchedule();
    }

    protected function tearDown(): void
    {
        putenv('PABLO_STAMPS_DIR');
        array_map('unlink', glob($this->stamps.'/*') ?: []);
        @rmdir($this->stamps);
    }

    private function cfg(string $name, int $pollInterval = 10): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repoPath: '/tmp/'.$name,
            primaryBranch: 'main',
            worktreesRoot: '/tmp/wt/'.$name,
            provider: 'github',
            identity: 'octocat',
            projectKey: 'PR',
            syncStrategy: 'rebase',
            syncAutoApply: false,
            syncInterval: 720,
            pollInterval: $pollInterval,
            failureSignal: null,
            botWhitelist: [],
            ciIgnoreChecks: [],
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    private function stamp(string $project, int $unixTime): void
    {
        file_put_contents($this->stamps."/{$project}.poll", (string) $unixTime);
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    public function testNeverPolledIsDueAtTheNextTick(): void
    {
        $now = $this->at('2026-08-06T10:02:30+00:00');
        $w = $this->schedule->windowFor($this->cfg('a'), $now);

        $this->assertTrue($w->isFresh());
        $this->assertNull($w->lastRunAt);
        $this->assertSame('2026-08-06T10:05:00+00:00', $w->nextRunAt->format('c'));
    }

    /**
     * The dispatcher fires on a 5-minute grid and only then asks whether a
     * project is due, so the next poll lands on the first tick at or after
     * lastRun + interval — not on lastRun + interval itself.
     */
    public function testNextRunRoundsUpToTheDispatcherTick(): void
    {
        // last poll 10:01:00, interval 10m -> due 10:11:00 -> tick 10:15:00
        $this->stamp('a', $this->at('2026-08-06T10:01:00+00:00')->getTimestamp());
        $now = $this->at('2026-08-06T10:06:00+00:00');

        $w = $this->schedule->windowFor($this->cfg('a', 10), $now);

        $this->assertSame('2026-08-06T10:01:00+00:00', $w->lastRunAt?->format('c'));
        $this->assertSame('2026-08-06T10:15:00+00:00', $w->nextRunAt->format('c'));
        $this->assertSame(540, $w->secondsRemaining());
    }

    public function testDueInThePastStillYieldsAFutureTick(): void
    {
        $this->stamp('a', $this->at('2026-08-06T09:00:00+00:00')->getTimestamp());
        $now = $this->at('2026-08-06T10:02:00+00:00');

        $w = $this->schedule->windowFor($this->cfg('a', 10), $now);

        $this->assertSame('2026-08-06T10:05:00+00:00', $w->nextRunAt->format('c'));
    }

    public function testExactTickBoundaryIsNotPushedForward(): void
    {
        // due exactly at 10:10:00, which is itself a tick
        $this->stamp('a', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $now = $this->at('2026-08-06T10:03:00+00:00');

        $w = $this->schedule->windowFor($this->cfg('a', 10), $now);

        $this->assertSame('2026-08-06T10:10:00+00:00', $w->nextRunAt->format('c'));
    }

    public function testIntervalLongerThanTheTickGrid(): void
    {
        $this->stamp('a', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $now = $this->at('2026-08-06T10:30:00+00:00');

        $w = $this->schedule->windowFor($this->cfg('a', 120), $now);

        $this->assertSame('2026-08-06T12:00:00+00:00', $w->nextRunAt->format('c'));
        $this->assertSame(120, $w->intervalMinutes);
    }

    public function testPercentRemainingDrainsToZero(): void
    {
        $this->stamp('a', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $cfg = $this->cfg('a', 10);

        // right after the poll: nearly full
        $this->assertSame(100.0, $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:00:00+00:00'))->percentRemaining());
        // halfway to the 10:10 tick
        $this->assertSame(50.0, $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:05:00+00:00'))->percentRemaining());
        // at the tick
        $this->assertSame(0.0, $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:10:00+00:00'))->percentRemaining());
    }

    public function testGarbageStampIsTreatedAsNeverPolled(): void
    {
        file_put_contents($this->stamps.'/a.poll', "not-a-number\n");
        $this->assertTrue($this->schedule->windowFor($this->cfg('a'), $this->at('2026-08-06T10:00:00+00:00'))->isFresh());
    }

    public function testWindowsAreSortedBySoonestNextRun(): void
    {
        $this->stamp('slow', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $this->stamp('fast', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $now = $this->at('2026-08-06T10:01:00+00:00');

        $windows = $this->schedule->windows(
            ['slow' => $this->cfg('slow', 60), 'fast' => $this->cfg('fast', 5)],
            $now,
        );

        $this->assertSame(['fast', 'slow'], array_map(static fn ($w) => $w->project, $windows));
    }

    public function testBarWindowAnchoredToMostRecentPollReadsFull(): void
    {
        // The focus of the shared bar is whichever project polls soonest. Here
        // that project last polled at 09:56, but another project polled more
        // recently at 10:04. Anchoring the bar to the focus project's own stamp
        // would render it partially drained at this instant; anchoring to the
        // most recent poll across all projects resets it to full.
        $this->stamp('focus', $this->at('2026-08-06T09:56:00+00:00')->getTimestamp());
        $this->stamp('other', $this->at('2026-08-06T10:04:00+00:00')->getTimestamp());
        $now = $this->at('2026-08-06T10:04:00+00:00');

        $bar = $this->schedule->barWindow(
            ['focus' => $this->cfg('focus', 10), 'other' => $this->cfg('other', 120)],
            $now,
        );

        $this->assertNotNull($bar);
        $this->assertSame('2026-08-06T10:04:00+00:00', $bar->lastRunAt?->format('c'));
        $this->assertSame(100.0, $bar->percentRemaining());
    }

    public function testBarWindowIsNullWithoutProjects(): void
    {
        $this->assertNull($this->schedule->barWindow([], $this->at('2026-08-06T10:04:00+00:00')));
    }

    public function testLastPollDurationSFromJsonStamp(): void
    {
        $ranAt = $this->at('2026-08-06T10:01:00+00:00')->getTimestamp();
        file_put_contents($this->stamps.'/a.poll', Dispatch::encodeStamp((float) $ranAt, 37.4));

        $this->assertSame(37.4, $this->schedule->lastPollDurationS('a'));
        $this->assertSame(37.4, $this->schedule->windowFor($this->cfg('a'))->lastRunDurationS);
        $this->assertSame('37s', $this->schedule->windowFor($this->cfg('a'))->lastRunDurationLabel());
    }

    public function testLastPollDurationUnknownForLegacyStamp(): void
    {
        // Legacy bare-float stamp predates duration tracking.
        $this->stamp('a', $this->at('2026-08-06T10:01:00+00:00')->getTimestamp());

        $this->assertNull($this->schedule->lastPollDurationS('a'));
        $this->assertNull($this->schedule->windowFor($this->cfg('a'))->lastRunDurationS);
    }

    public function testLastPollAcrossProjectsTakesTheMostRecent(): void
    {
        $this->stamp('a', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $this->stamp('b', $this->at('2026-08-06T11:00:00+00:00')->getTimestamp());

        $latest = $this->schedule->lastPollAcrossProjects(['a' => $this->cfg('a'), 'b' => $this->cfg('b')]);

        $this->assertSame('2026-08-06T11:00:00+00:00', $latest?->setTimezone(new \DateTimeZone('UTC'))->format('c'));
    }

    public function testLastPollAcrossProjectsIsNullWhenNoneRan(): void
    {
        $this->assertNull($this->schedule->lastPollAcrossProjects(['a' => $this->cfg('a')]));
    }

    public function testRemainingLabel(): void
    {
        $this->stamp('a', $this->at('2026-08-06T10:00:00+00:00')->getTimestamp());
        $cfg = $this->cfg('a', 10);

        $this->assertSame('due now', $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:10:00+00:00'))->remainingLabel());
        $this->assertSame('30s', $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:09:30+00:00'))->remainingLabel());
        $this->assertSame('5m', $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:05:00+00:00'))->remainingLabel());
        $this->assertSame('4m 30s', $this->schedule->windowFor($cfg, $this->at('2026-08-06T10:05:30+00:00'))->remainingLabel());
    }
}
