<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Domain\Time;
use Pablo\Support\Numbers;

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
    /** Dimensions runBreakdown() groups finished agent runs by. */
    public const BREAKDOWNS = ['agent', 'project', 'model', 'task'];

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
                    'duration_s' => Numbers::int($event->get('duration_s')),
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
        $superseded = self::supersededFinishedEvents($events);
        foreach ($events as $event) {
            if ('agent_run_started' === $event->type()) {
                $name = (string) $event->get('agent');
                if ('' !== $agentFilter && $name !== $agentFilter) {
                    continue;
                }
                $started[$name] = ($started[$name] ?? 0) + 1;
            } elseif ('agent_run_finished' === $event->type()) {
                if (isset($superseded[spl_object_id($event)])) {
                    continue; // duplicate emission of an already-counted run
                }
                $name = (string) $event->get('agent');
                if ('' !== $agentFilter && $name !== $agentFilter) {
                    continue;
                }
                $row = $byAgent[$name] ?? self::emptyAgentRow();
                ++$row['runs'];
                $row['duration_sum'] += max(0, Numbers::int($event->get('duration_s')));
                $row['in'] += max(0, Numbers::int($event->get('input')));
                $row['out'] += max(0, Numbers::int($event->get('output')));
                $row['reasoning'] += max(0, Numbers::int($event->get('reasoning')));
                $row['cache_read'] += max(0, Numbers::int($event->get('cache_read')));
                $row['cache_write'] += max(0, Numbers::int($event->get('cache_write')));
                $row['cost'] += abs(Numbers::float($event->get('cost')));
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
     * Finished agent runs grouped by one dimension (see BREAKDOWNS), sorted by
     * total tokens descending (ties: runs). A run used several models is
     * grouped under the comma-joined, sorted model list: the log records the
     * run's tokens, not a per-model split.
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return array<string, array{runs: int, duration_s: int, avg_run_s: int, tokens_in: int, tokens_out: int, cache_read: int, cache_write: int, tokens_total: int, cost: float|null}>
     */
    public function runBreakdown(array $events, string $by, string $agentFilter = ''): array
    {
        if (!\in_array($by, self::BREAKDOWNS, true)) {
            throw new \InvalidArgumentException("unknown breakdown '{$by}'");
        }
        $superseded = self::supersededFinishedEvents($events);
        $groups = [];
        foreach ($events as $event) {
            if ('agent_run_finished' !== $event->type() || isset($superseded[spl_object_id($event)])) {
                continue;
            }
            if ('' !== $agentFilter && (string) $event->get('agent') !== $agentFilter) {
                continue;
            }
            $key = match ($by) {
                'agent' => (string) $event->get('agent'),
                'project' => $event->project(),
                'model' => self::modelKey($event),
                default => $event->project().'/'.$event->branch(),
            };
            $row = $groups[$key] ?? ['runs' => 0, 'duration_s' => 0, 'avg_run_s' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cache_read' => 0, 'cache_write' => 0, 'tokens_total' => 0, 'cost' => null];
            $usage = self::runUsage($event);
            ++$row['runs'];
            $row['duration_s'] += $usage['duration_s'];
            $row['tokens_in'] += $usage['tokens_in'];
            $row['tokens_out'] += $usage['tokens_out'];
            $row['cache_read'] += $usage['cache_read'];
            $row['cache_write'] += $usage['cache_write'];
            $row['tokens_total'] += $usage['tokens_in'] + $usage['tokens_out'] + $usage['cache_read'] + $usage['cache_write'];
            if (null !== $usage['cost']) {
                $row['cost'] = round(($row['cost'] ?? 0.0) + $usage['cost'], 4);
            }
            $row['avg_run_s'] = (int) round($row['duration_s'] / $row['runs']);
            $groups[$key] = $row;
        }
        uksort($groups, static fn (string $a, string $b) => [$groups[$b]['tokens_total'], $groups[$b]['runs'], $a] <=> [$groups[$a]['tokens_total'], $groups[$a]['runs'], $b]);

        return $groups;
    }

    /**
     * One task's analytics as a chronological timeline: its open/close, every
     * state entry with its dwell time (until the next entry or the close; -1
     * while still open) and every finished agent run with its usage.
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return list<array<string, mixed>>
     */
    public function taskTimeline(array $events, string $project, string $branch): array
    {
        $own = array_values(array_filter(
            $events,
            static fn (AnalyticsEvent $e) => $e->project() === $project && $e->branch() === $branch,
        ));
        usort($own, fn (AnalyticsEvent $a, AnalyticsEvent $b) => $this->tsOf($a) <=> $this->tsOf($b));
        $superseded = self::supersededFinishedEvents($own);

        $timeline = [];
        foreach ($own as $i => $event) {
            $base = ['ts' => $event->ts(), 'event' => $event->type()];
            switch ($event->type()) {
                case 'task_opened':
                    $timeline[] = $base + ['initial_state' => (string) $event->get('initial_state')];
                    break;
                case 'state_entered':
                    $dwell = -1;
                    foreach (\array_slice($own, $i + 1) as $next) {
                        if (\in_array($next->type(), ['state_entered', 'task_closed'], true)) {
                            $dwell = max(0, $this->tsOf($next) - $this->tsOf($event));
                            break;
                        }
                    }
                    $timeline[] = $base + ['from' => (string) $event->get('from'), 'to' => (string) $event->get('to'), 'dwell_s' => $dwell];
                    break;
                case 'agent_run_finished':
                    if (isset($superseded[spl_object_id($event)])) {
                        break;
                    }
                    $timeline[] = $base + [
                        'agent' => (string) $event->get('agent'),
                        'started_at' => (string) $event->get('started_at'),
                        'models' => self::modelKey($event),
                        'harvest' => (string) $event->get('harvest', 'none'),
                    ] + self::runUsage($event);
                    break;
                case 'task_closed':
                    $timeline[] = $base + [
                        'final_state' => (string) $event->get('final_state'),
                        'merged' => (bool) $event->get('merged', false),
                        'duration_s' => Numbers::int($event->get('duration_s')),
                    ];
                    break;
            }
        }

        return $timeline;
    }

    /** @return array{duration_s: int, tokens_in: int, tokens_out: int, cache_read: int, cache_write: int, cost: float|null} */
    private static function runUsage(AnalyticsEvent $event): array
    {
        $cost = $event->get('cost');

        return [
            'duration_s' => max(0, Numbers::int($event->get('duration_s'))),
            'tokens_in' => max(0, Numbers::int($event->get('input'))),
            // Same convention as agentStats: reasoning counts as output.
            'tokens_out' => max(0, Numbers::int($event->get('output'))) + max(0, Numbers::int($event->get('reasoning'))),
            'cache_read' => max(0, Numbers::int($event->get('cache_read'))),
            'cache_write' => max(0, Numbers::int($event->get('cache_write'))),
            'cost' => null === $cost ? null : abs(Numbers::float($cost)),
        ];
    }

    private static function modelKey(AnalyticsEvent $event): string
    {
        $models = $event->get('models');
        $names = \is_array($models) ? array_values(array_unique(array_filter(array_map(
            static fn (mixed $m) => \is_scalar($m) ? (string) $m : '',
            $models,
        )))) : [];
        sort($names);

        return [] === $names ? '(unknown)' : implode(', ', $names);
    }

    /**
     * Duplicate finished emissions (version skew, a duplicated watcher or a
     * sweep racing its watcher) must never double-count a run. Twins share
     * project + branch + agent + started_at + finished_at; all but the
     * richest row of each group are skipped by agentStats.
     *
     * @param list<AnalyticsEvent> $events
     *
     * @return array<int, true> spl_object_id => true for every event to skip
     */
    private static function supersededFinishedEvents(array $events): array
    {
        /** @var array<string, AnalyticsEvent> $best */
        $best = [];
        $skip = [];
        foreach ($events as $event) {
            if ('agent_run_finished' !== $event->type()) {
                continue;
            }
            $key = implode('|', [
                $event->project(),
                $event->branch(),
                (string) $event->get('agent'),
                (string) $event->get('started_at'),
                (string) $event->get('finished_at'),
            ]);
            $incumbent = $best[$key] ?? null;
            if (null === $incumbent) {
                $best[$key] = $event;

                continue;
            }
            $loser = self::richerThan($event, $incumbent) ? $incumbent : $event;
            if ($loser === $incumbent) {
                $best[$key] = $event;
            }
            $skip[spl_object_id($loser)] = true;
        }

        return $skip;
    }

    private static function richerThan(AnalyticsEvent $candidate, AnalyticsEvent $incumbent): bool
    {
        return self::richness($candidate) > self::richness($incumbent);
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function richness(AnalyticsEvent $event): array
    {
        $quality = match ((string) $event->get('harvest')) {
            'exact' => 2,
            'window' => 1,
            default => 0,
        };

        return [
            'unknown' === (string) $event->get('backend') ? 0 : 1,
            $quality,
            null !== $event->get('tokens_total') || '' !== (string) $event->get('session_id') ? 1 : 0,
        ];
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
}
