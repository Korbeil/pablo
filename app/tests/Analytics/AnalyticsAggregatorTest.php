<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\AnalyticsAggregator;
use Pablo\Analytics\AnalyticsEvent;
use Pablo\Domain\Time;
use PHPUnit\Framework\TestCase;

final class AnalyticsAggregatorTest extends TestCase
{
    private const BASE = '-400000'; // seconds ago

    public function testTaskStatsAggregatesPerProject(): void
    {
        $events = $this->lifecycle();
        $stats = $this->aggregator()->taskStats($events);

        $this->assertSame(['proj'], array_keys($stats));
        $this->assertSame(1, $stats['proj']['opened']);
        $this->assertSame(1, $stats['proj']['closed']);
        $this->assertSame(100.0, $stats['proj']['merge_rate_pct']);
        $this->assertSame(9600, $stats['proj']['avg_life_s']);
    }

    public function testAgentStatsComputesCacheHitAndUserWait(): void
    {
        $stats = $this->aggregator()->agentStats($this->lifecycle());

        $this->assertSame(['task-analyst'], array_keys($stats));
        $agent = $stats['task-analyst'];
        $this->assertSame(1, $agent['runs']);
        $this->assertSame(1, $agent['starts']);
        $this->assertSame(1200, $agent['avg_run_s']);
        // cache_read / (input + cache_read + cache_write) = 500/1600 = 31.3%
        $this->assertEqualsWithDelta(31.3, $agent['cache_hit_pct'], 0.05);
        $this->assertSame(2400, $agent['avg_user_wait_s']);
        // Raw splits exposed for the stacked token chart.
        $this->assertSame(500, $agent['cache_read']);
        $this->assertSame(100, $agent['cache_write']);
    }

    public function testStateStatsBoundsDwellByFollowingTransition(): void
    {
        $stats = $this->aggregator()->stateStats($this->lifecycle());

        $this->assertSame(600, $stats['in-progress']['avg_dwell_s']);
        $this->assertSame(4200, $stats['draft']['avg_dwell_s']);
        $this->assertSame(-1, $stats['ready-to-review']['avg_dwell_s']); // open-ended
        $this->assertSame(1, $stats['draft']['entries']);
    }

    public function testDailySeriesZeroFillsBetweenFirstAndLastEvent(): void
    {
        $series = $this->aggregator()->dailySeries($this->lifecycle(), null);

        // The lifecycle spans t0..t5 = 9600s, i.e. two UTC days unless the
        // fixture straddles midnight exactly; either way continuity holds.
        $days = array_column($series, 'date');
        $this->assertNotEmpty($days);
        $totalOpened = array_sum(array_column($series, 'opened'));
        $totalClosed = array_sum(array_column($series, 'closed'));
        $this->assertSame(1, $totalOpened);
        $this->assertSame(1, $totalClosed);

        $cursor = new \DateTimeImmutable(reset($days).'T00:00:00+00:00');
        foreach ($days as $day) {
            $this->assertSame($day, $cursor->format('Y-m-d'), 'no gaps between consecutive days');
            $cursor = $cursor->modify('+1 day');
        }
    }

    public function testDailySeriesStartsAtSinceWhenEarlierThanFirstEvent(): void
    {
        $since = new \DateTimeImmutable('2026-01-09T00:00:00+00:00');
        $series = $this->aggregator()->dailySeries($this->fixedLifecycle(), $since);

        $this->assertSame(['2026-01-09', '2026-01-10', '2026-01-11'], array_column($series, 'date'));
        $this->assertSame(0, $series[0]['opened']);
    }

    public function testDailySeriesIsEmptyWhenEventsEndBeforeSince(): void
    {
        $since = new \DateTimeImmutable('2026-01-15T00:00:00+00:00');
        $this->assertSame([], $this->aggregator()->dailySeries($this->fixedLifecycle(), $since));
    }

    public function testEmptyEventsGiveEmptySeries(): void
    {
        $this->assertSame([], $this->aggregator()->dailySeries([], null));
    }

    /** Two fixed-date events (opened on the 10th, closed on the 11th).
     *
     * @return list<AnalyticsEvent>
     */
    private function fixedLifecycle(): array
    {
        return [
            AnalyticsEvent::fromEvent(['ts' => '2026-01-10T12:00:00+00:00', 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'wk-1']),
            AnalyticsEvent::fromEvent(['ts' => '2026-01-11T08:00:00+00:00', 'type' => 'task_closed', 'project' => 'proj', 'branch' => 'wk-1']),
        ];
    }

    /**
     * One complete lifecycle: opened → in-progress → draft, one agent run
     * finishing at t0+2400, draft left at t0+4800 (user waited 2400s),
     * closed merged at t0+9600.
     *
     * @return list<AnalyticsEvent>
     */
    private function lifecycle(): array
    {
        $iso = static fn (int $offset): string => gmdate('Y-m-d\TH:i:sP', time() + ((int) self::BASE) + $offset);
        $t0 = (int) self::BASE;

        return [
            AnalyticsEvent::fromEvent(['ts' => $iso(0), 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'wk-1']),
            AnalyticsEvent::fromEvent(['ts' => $iso(0), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'in-progress', 'to' => 'in-progress']),
            AnalyticsEvent::fromEvent(['ts' => $iso(600), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'in-progress', 'to' => 'draft']),
            AnalyticsEvent::fromEvent(['ts' => $iso(1200), 'type' => 'agent_run_started', 'project' => 'proj', 'branch' => 'wk-1', 'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca', 'launched_at' => $iso(1200)]),
            AnalyticsEvent::fromEvent([
                'ts' => $iso(2400), 'type' => 'agent_run_finished', 'project' => 'proj', 'branch' => 'wk-1',
                'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca',
                'started_at' => $iso(1200), 'finished_at' => $iso(2400), 'duration_s' => 1200,
                'input' => 1000, 'output' => 200, 'reasoning' => 50, 'cache_read' => 500,
                'cache_write' => 100, 'tokens_total' => 1850, 'cost' => 0.25,
                'models' => ['anthropic/claude'], 'session_id' => 'ses_a', 'harvest' => 'exact',
            ]),
            AnalyticsEvent::fromEvent(['ts' => $iso(4800), 'type' => 'state_entered', 'project' => 'proj', 'branch' => 'wk-1', 'from' => 'draft', 'to' => 'ready-to-review']),
            AnalyticsEvent::fromEvent([
                'ts' => $iso(9600), 'type' => 'task_closed', 'project' => 'proj', 'branch' => 'wk-1',
                'opened_at' => $iso(0), 'closed_at' => $iso(9600), 'final_state' => 'waiting-review',
                'merged' => true, 'pr_number' => 7, 'agent_runs' => ['task-analyst' => 2], 'duration_s' => 9600,
            ]),
        ];
    }

    public function testDuplicateFinishedRunsAreCountedOnceWithRichestRowWinning(): void
    {
        $iso = static fn (int $offset): string => gmdate('Y-m-d\TH:i:sP', time() + ((int) self::BASE) + $offset);

        $events = [
            AnalyticsEvent::fromEvent(['ts' => $iso(0), 'type' => 'task_opened', 'project' => 'proj', 'branch' => 'wk-1']),
            AnalyticsEvent::fromEvent(['ts' => $iso(100), 'type' => 'agent_run_started', 'project' => 'proj', 'branch' => 'wk-1', 'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'orca', 'launched_at' => $iso(0)]),
            // Watcher emission: real backend, window harvest with usage.
            AnalyticsEvent::fromEvent([
                'ts' => $iso(200), 'type' => 'agent_run_finished', 'project' => 'proj', 'branch' => 'wk-1',
                'run_id' => 'r1', 'agent' => 'task-analyst', 'backend' => 'openchamber',
                'started_at' => $iso(0), 'finished_at' => $iso(200), 'duration_s' => 200,
                'input' => 300, 'output' => 40, 'reasoning' => 10, 'cache_read' => 700,
                'cache_write' => 0, 'tokens_total' => 1050, 'cost' => 0.0,
                'models' => ['opencode-go/x'], 'session_id' => 'ses_a', 'harvest' => 'window',
            ]),
            // Sweep twin: same run identity, unknown backend, no usage — skipped.
            AnalyticsEvent::fromEvent([
                'ts' => $iso(4000), 'type' => 'agent_run_finished', 'project' => 'proj', 'branch' => 'wk-1',
                'run_id' => 'random', 'agent' => 'task-analyst', 'backend' => 'unknown',
                'started_at' => $iso(0), 'finished_at' => $iso(200), 'duration_s' => 200,
                'input' => null, 'output' => null, 'reasoning' => null, 'cache_read' => null,
                'cache_write' => null, 'tokens_total' => null, 'cost' => null,
                'models' => [], 'session_id' => null, 'harvest' => 'none',
            ]),
        ];

        $agent = $this->aggregator()->agentStats($events)['task-analyst'];

        $this->assertSame(1, $agent['runs']);
        $this->assertSame(1, $agent['starts']);
        $this->assertSame(300, $agent['tokens_in']);
        $this->assertSame(700, $agent['cache_read']);
    }

    private function aggregator(): AnalyticsAggregator
    {
        return new AnalyticsAggregator(new Time());
    }
}
