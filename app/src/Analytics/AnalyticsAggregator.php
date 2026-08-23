<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Domain\Time;

/**
 * The single source of truth for analytics aggregation, shared by the
 * `pablo stats` tables and the dashboard's /analytics charts so the two
 * surfaces cannot drift (same rule as PrBadge/AgentActivity for listings).
 *
 * All methods take pre-filtered AnalyticsEvent lists (the caller owns window
 * and project filtering) and return plain scalar structures ready for both
 * terminal rendering and chart datasets.
 */
final class AnalyticsAggregator
{
    public function __construct(
        private readonly Time $time = new Time(),
    ) {
    }

    /**
     * Per-project task-lifetime aggregates.
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return array<string, array<string, float|int|string>>
     */
    public function taskStats(array $events): array
    {
        /** @var array<string, array{ts: string, project: string}> $openedAt */
        $openedAt = [];
        /** @var array<string, array{duration_s: int, merged: bool}> $closed */
        $closed = [];
        foreach ($events as $event) {
            $key = $event->project().'/'.$event->branch();
            if ('task_opened' === $event->type()) {
                $openedAt[$key] ??= ['ts' => $event->ts(), 'project' => $event->project()];
            } elseif ('task_closed' === $event->type()) {
                $closed[$key] = [
                    'duration_s' => self::intOf($event->get('duration_s')),
                    'merged' => (bool) $event->get('merged', false),
                ];
            }
        }

        /** @var array<string, array<int, int>> $durationsByProject */
        $durationsByProject = [];
        /** @var array<string, array{opened: int, closed: int, merged: int}> $counts */
        $counts = [];
        foreach ($openedAt as $key => $meta) {
            $project = $meta['project'];
            $counts[$project] ??= ['opened' => 0, 'closed' => 0, 'merged' => 0];
            ++$counts[$project]['opened'];
            if (isset($closed[$key])) {
                ++$counts[$project]['closed'];
                if ($closed[$key]['merged']) {
                    ++$counts[$project]['merged'];
                }
                $durationsByProject[$project][] = $closed[$key]['duration_s'];
            }
        }

        $stats = [];
        foreach ($counts as $project => $c) {
            $stats[$project] = [
                'opened' => $c['opened'],
                'closed' => $c['closed'],
                'merge_rate_pct' => $c['closed'] > 0 ? round(100 * $c['merged'] / $c['closed'], 1) : 0,
                'avg_life_s' => self::avg($durationsByProject[$project] ?? []),
                'p50_life_s' => self::percentile($durationsByProject[$project] ?? [], 50),
                'p90_life_s' => self::percentile($durationsByProject[$project] ?? [], 90),
            ];
        }

        return $stats;
    }

    /**
     * Per-agent run aggregates. $agentFilter restricts to one agent (empty
     * string = all).
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return array<string, array<string, float|int|string>>
     */
    public function agentStats(array $events, string $agentFilter = ''): array
    {
        /** @var array<string, array<string, float|int>> $byAgent */
        $byAgent = [];
        /** @var array<string, list<string>> $stateTsByKey state_entered timestamps per project/branch */
        $stateTsByKey = [];
        foreach ($events as $event) {
            if ('state_entered' === $event->type()) {
                $stateTsByKey[$event->project().'/'.$event->branch()][] = $event->ts();
            }
        }
        foreach ($stateTsByKey as $list) {
            sort($list);
        }

        /** @var array<string, int> $started */
        $started = [];
        foreach ($events as $event) {
            if ('agent_run_started' === $event->type()) {
                $name = (string) $event->get('agent');
                if ('' !== $agentFilter && $name !== $agentFilter) {
                    continue;
                }
                $started[$name] = ($started[$name] ?? 0) + 1;
            } elseif ('agent_run_finished' === $event->type()) {
                $name = (string) $event->get('agent');
                if ('' !== $agentFilter && $name !== $agentFilter) {
                    continue;
                }
                $row = $byAgent[$name] ?? self::emptyAgentRow();
                ++$row['runs'];
                $row['duration_sum'] += max(0, self::intOf($event->get('duration_s')));
                $row['in'] += max(0, self::intOf($event->get('input')));
                $row['out'] += max(0, self::intOf($event->get('output')));
                $row['reasoning'] += max(0, self::intOf($event->get('reasoning')));
                $row['cache_read'] += max(0, self::intOf($event->get('cache_read')));
                $row['cache_write'] += max(0, self::intOf($event->get('cache_write')));
                $row['cost'] += abs(self::floatOf($event->get('cost')));
                $waitS = $this->humanWaitS($event, $stateTsByKey[$event->project().'/'.$event->branch()] ?? []);
                if (null !== $waitS) {
                    ++$row['waits'];
                    $row['wait_sum'] += $waitS;
                }
                $byAgent[$name] = $row;
            }
        }

        $stats = [];
        foreach ($byAgent as $name => $row) {
            $runs = (int) $row['runs'];
            $context = (int) $row['in'] + (int) $row['cache_read'] + (int) $row['cache_write'];
            $stats[$name] = [
                'runs' => $runs,
                'starts' => $started[$name] ?? 0,
                'avg_run_s' => $runs > 0 ? (int) round($row['duration_sum'] / $runs) : 0,
                'tokens_in' => (int) $row['in'],
                'tokens_out' => (int) $row['out'] + (int) $row['reasoning'],
                'reasoning' => (int) $row['reasoning'],
                'context_tokens' => $context,
                'cache_read' => (int) $row['cache_read'],
                'cache_write' => (int) $row['cache_write'],
                'cache_hit_pct' => $context > 0 ? round(100 * $row['cache_read'] / $context, 1) : 0.0,
                'cost' => round($row['cost'], 4),
                'avg_user_wait_s' => $row['waits'] > 0 ? (int) round($row['wait_sum'] / $row['waits']) : -1,
            ];
        }
        uasort($stats, static fn (array $a, array $b) => $b['runs'] <=> $a['runs']);

        return $stats;
    }

    /**
     * Average dwell time per state plus entry counts (the rework-loop signal:
     * ci-red / request-changes churn).
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return array<string, array<string, float|int|string>>
     */
    public function stateStats(array $events): array
    {
        /** @var array<string, list<array{ts: int, to: string}>> $entriesByKey */
        $entriesByKey = [];
        foreach ($events as $event) {
            if ('state_entered' !== $event->type()) {
                continue;
            }
            $entriesByKey[$event->project().'/'.$event->branch()][] = [
                'ts' => $this->tsOf($event),
                'to' => (string) $event->get('to'),
            ];
        }

        /** @var array<string, array{n: int, bounded: int, sum: int}> $byState */
        $byState = [];
        foreach ($entriesByKey as $entries) {
            usort($entries, static fn (array $a, array $b) => $a['ts'] <=> $b['ts']);
            foreach ($entries as $i => $entry) {
                $byState[$entry['to']] ??= ['n' => 0, 'bounded' => 0, 'sum' => 0];
                ++$byState[$entry['to']]['n'];
                if (isset($entries[$i + 1])) {
                    ++$byState[$entry['to']]['bounded'];
                    $byState[$entry['to']]['sum'] += max(0, $entries[$i + 1]['ts'] - $entry['ts']);
                }
            }
        }

        $stats = [];
        foreach ($byState as $state => $row) {
            $stats[$state] = [
                'entries' => $row['n'],
                // A single entry can still have a bounded dwell when the task
                // moved on; only open-ended last entries lack one.
                'avg_dwell_s' => $row['bounded'] > 0 ? (int) round($row['sum'] / $row['bounded']) : -1,
            ];
        }
        uasort($stats, static fn (array $a, array $b) => $b['entries'] <=> $a['entries']);

        return $stats;
    }

    /**
     * Tasks opened/closed per UTC day covering every day between $since (or
     * the first event's day) and the last event's day, zero-filled, so chart
     * labels are continuous.
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return list<array{date: string, opened: int, closed: int}>
     */
    public function dailySeries(array $events, ?\DateTimeImmutable $since): array
    {
        /** @var array<string, int> $openedByDay */
        $openedByDay = [];
        /** @var array<string, int> $closedByDay */
        $closedByDay = [];
        foreach ($events as $event) {
            $day = gmdate('Y-m-d', $this->tsOf($event));
            if ('task_opened' === $event->type()) {
                $openedByDay[$day] = ($openedByDay[$day] ?? 0) + 1;
            } elseif ('task_closed' === $event->type()) {
                $closedByDay[$day] = ($closedByDay[$day] ?? 0) + 1;
            }
        }
        if ([] === $openedByDay && [] === $closedByDay) {
            return [];
        }
        $seenDays = array_merge(array_keys($openedByDay), array_keys($closedByDay));
        if ([] === $seenDays) {
            return [];
        }
        $firstDay = $since?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d') ?? min($seenDays);
        $lastDay = max($seenDays);

        $series = [];
        $cursor = new \DateTimeImmutable($firstDay.'T00:00:00+00:00');
        $end = new \DateTimeImmutable($lastDay.'T00:00:00+00:00');
        while ($cursor <= $end) {
            $day = $cursor->format('Y-m-d');
            $series[] = [
                'date' => $day,
                'opened' => $openedByDay[$day] ?? 0,
                'closed' => $closedByDay[$day] ?? 0,
            ];
            $cursor = $cursor->modify('+1 day');
        }

        return $series;
    }

    /**
     * Seconds between an agent finishing and the task's next state change
     * (human latency), or null when no later transition is recorded.
     *
     * @param list<string> $stateTimestamps sorted ascending
     */
    private function humanWaitS(AnalyticsEvent $finished, array $stateTimestamps): ?int
    {
        $at = $this->tsOf($finished);
        foreach ($stateTimestamps as $ts) {
            $candidate = $this->time->parseTs($ts)->getTimestamp();
            if ($candidate > $at) {
                return max(0, $candidate - $at);
            }
        }

        return null;
    }

    private function tsOf(AnalyticsEvent $event): int
    {
        try {
            return $this->time->parseTs($event->ts())->getTimestamp();
        } catch (\Throwable) {
            return 0;
        }
    }

    /** @return array<string, float|int> */
    private static function emptyAgentRow(): array
    {
        return [
            'runs' => 0, 'duration_sum' => 0, 'in' => 0, 'out' => 0, 'reasoning' => 0,
            'cache_read' => 0, 'cache_write' => 0, 'cost' => 0.0, 'waits' => 0, 'wait_sum' => 0,
        ];
    }

    /**
     * @param array<int, int> $values
     */
    private static function percentile(array $values, int $p): int
    {
        if ([] === $values) {
            return -1;
        }
        $sorted = $values;
        sort($sorted);
        $index = (int) round(($p / 100) * (\count($sorted) - 1));

        return (int) $sorted[$index];
    }

    /**
     * @param array<int, int> $values
     */
    private static function avg(array $values): int
    {
        return [] !== $values ? (int) round(array_sum($values) / \count($values)) : -1;
    }

    private static function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
