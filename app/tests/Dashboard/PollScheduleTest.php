<?php

declare(strict_types=1);

namespace Pablo\Tests\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Config\RepoConfig;
use Pablo\Config\StatePollingConfig;
use Pablo\Config\SyncConfig;
use Pablo\Config\TrackerConfig;
use Pablo\Dashboard\PollSchedule;
use Pablo\Tests\SandboxedEnv;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

final class PollScheduleTest extends TestCase
{
    use SandboxedEnv;

    private string $stamps;
    private string $config;
    private PollSchedule $schedule;

    protected function setUp(): void
    {
        $this->stamps = TempDir::create('poll');
        $this->config = TempDir::file('config', '.yaml');
        $this->setEnv('PABLO_STAMPS_DIR', $this->stamps);
        $this->setEnv('PABLO_CONFIG', $this->config);
        // Most cases below pin the arithmetic on a 5-minute grid.
        $this->tick(5);
        $this->schedule = new PollSchedule(new \Pablo\Dispatch\Stamps());
    }

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    private function tick(?int $minutes): void
    {
        file_put_contents($this->config, null === $minutes ? '' : "dispatch:\n  tick_minutes: {$minutes}\n");
    }

    private function cfg(string $name, int $pollInterval = 10): ProjectConfig
    {
        return new ProjectConfig(
            name: $name,
            type: 'work',
            repo: new RepoConfig(
                path: '/tmp/'.$name,
                primaryBranch: 'main',
                worktreesRoot: '/tmp/wt/'.$name,
            ),
            tracker: new TrackerConfig(
                provider: 'github',
                identity: 'octocat',
                projectKey: 'PR',
            ),
            sync: new SyncConfig(
                strategy: 'rebase',
                autoApply: false,
                intervalMinutes: 720,
            ),
            statePolling: new StatePollingConfig($pollInterval),
            defaultModel: 'openrouter/test/model',
            prDescriptionLocale: 'en',
        );
    }

    private function stamp(string $project, int $unixTime): void
    {
        (new \Pablo\Dispatch\Stamps())->write($project, 'poll', (float) $unixTime, 0.0);
    }

    private function at(string $iso): \DateTimeImmutable
    {
        return new \DateTimeImmutable($iso, new \DateTimeZone('UTC'));
    }

    public function testNeverPolledIsDueAtTheNextTick(): void
    {
        $now = $this->at('2026-08-06T10:02:30+00:00');
        $w = $this->schedule->windowFor($this->cfg('a'), $now);

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

    public function testDefaultTickIsOneMinute(): void
    {
        $this->tick(null);
        // last poll 10:01:20, interval 10m -> due 10:11:20 (less the grace) -> tick 10:11:00
        $this->stamp('a', $this->at('2026-08-06T10:01:20+00:00')->getTimestamp());

        $w = $this->schedule->windowFor($this->cfg('a', 10), $this->at('2026-08-06T10:06:00+00:00'));

        $this->assertSame('2026-08-06T10:11:00+00:00', $w->nextRunAt->format('c'));
    }

    public function testAnInvalidTickFallsBackToTheDefault(): void
    {
        $this->tick(7);
        $w = $this->schedule->windowFor($this->cfg('a'), $this->at('2026-08-06T10:02:30+00:00'));

        $this->assertSame('2026-08-06T10:03:00+00:00', $w->nextRunAt->format('c'));
    }

    /**
     * systemd fires on local wall-clock minutes (OnCalendar=*:0/30): in a
     * +05:30 zone that is :00 and :30 local, not unix multiples of 30 minutes.
     */
    public function testTicksFollowTheLocalClockInAHalfHourZone(): void
    {
        $this->tick(30);
        $now = new \DateTimeImmutable('2026-08-06T10:10:00+05:30');

        $w = $this->schedule->windowFor($this->cfg('a'), $now);

        $this->assertSame('2026-08-06T10:30:00+05:30', $w->nextRunAt->format('c'));
    }

    /**
     * A tick landing a few seconds before the interval has fully elapsed
     * still runs the job (Dispatch::DUE_GRACE_SECONDS): no extra tick of wait.
     */
    public function testATickJustBeforeTheDueTimeCounts(): void
    {
        $this->stamp('a', $this->at('2026-08-06T10:00:10+00:00')->getTimestamp());

        $w = $this->schedule->windowFor($this->cfg('a', 10), $this->at('2026-08-06T10:03:00+00:00'));

        $this->assertSame('2026-08-06T10:10:00+00:00', $w->nextRunAt->format('c'));
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
        $this->assertNull($this->schedule->windowFor($this->cfg('a'), $this->at('2026-08-06T10:00:00+00:00'))->lastRunAt);
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
        (new \Pablo\Dispatch\Stamps())->write('a', 'poll', (float) $ranAt, 37.4);

        $this->assertSame(37.4, $this->schedule->lastPollDurationS('a'));
        $this->assertSame(37.4, $this->schedule->windowFor($this->cfg('a'))->lastRunDurationS);
        $this->assertSame('37s', $this->schedule->windowFor($this->cfg('a'))->lastRunDurationLabel());
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
