<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Support\Numbers;
use Symfony\UX\Chartjs\Model\Chart;

/**
 * Builds the /analytics charts from AnalyticsAggregator output. Pure
 * data-in, Chart-objects-out: no reads, no clock — the controller owns both,
 * exactly like the terminal side (StatsCommand owns filtering, the
 * aggregator owns numbers).
 *
 * @phpstan-type AgentRow array<string, float|int|string>
 * @phpstan-type StateRow array<string, float|int|string>
 * @phpstan-type BreakdownRow array{runs: int, duration_s: int, avg_run_s: int, tokens_in: int, tokens_out: int, cache_read: int, cache_write: int, tokens_total: int, cost: float|null}
 */
final class AnalyticsCharts
{
    // The PABLO website palette (site/style.css); readable on both the light
    // and the dark dashboard theme.
    private const BLUE = '#5b8def';
    private const GREEN = '#4caf50';
    private const INFO = '#4fb3c8';
    private const PURPLE = '#9a7cf0';
    private const YELLOW = '#e9ce37';
    private const RED = '#e5534b';

    /** Groups a breakdown chart draws before folding the rest into OTHER. */
    public const TOP = 10;
    public const OTHER = '(other)';

    public function __construct()
    {
    }

    /** @param list<array{date: string, opened: int, closed: int}> $daily */
    public function throughputChart(array $daily): Chart
    {
        return $this->bar(
            array_map(static fn (array $d) => substr((string) $d['date'], 5), $daily),
            [
                self::dataset('Opened', array_map(static fn (array $d) => (int) $d['opened'], $daily), self::YELLOW),
                self::dataset('Closed', array_map(static fn (array $d) => (int) $d['closed'], $daily), self::GREEN),
            ],
        );
    }

    /**
     * Full context breakdown per agent: what was re-sent fresh (input),
     * generated (output incl. reasoning), and served by the prompt cache
     * (cache read / write).
     *
     * @param array<string, AgentRow> $agents
     */
    public function tokensChart(array $agents): Chart
    {
        return $this->bar(
            array_keys($agents),
            [
                self::dataset('Input', self::col($agents, 'tokens_in'), self::BLUE),
                self::dataset('Output', self::col($agents, 'tokens_out'), self::GREEN),
                self::dataset('Cache read', self::col($agents, 'cache_read'), self::INFO),
                self::dataset('Cache write', self::col($agents, 'cache_write'), self::PURPLE),
            ],
            null,
            true,
        );
    }

    /**
     * @param array<string, AgentRow> $agents
     */
    public function cacheHitChart(array $agents): Chart
    {
        return $this->bar(
            array_keys($agents),
            [self::dataset('Cache hit %', self::floatCol($agents, 'cache_hit_pct'), self::INFO)],
            ['max' => 100],
        );
    }

    /**
     * @param array<string, AgentRow> $agents
     */
    public function runtimeChart(array $agents): Chart
    {
        return $this->bar(
            array_keys($agents),
            [self::dataset('Avg runtime (s)', self::col($agents, 'avg_run_s'), self::YELLOW)],
            null,
            false,
            true,
        );
    }

    /**
     * @param array<string, AgentRow> $agents
     */
    public function costChart(array $agents): Chart
    {
        $chart = new Chart(Chart::TYPE_DOUGHNUT);
        $names = array_keys($agents);
        $colors = [self::BLUE, self::GREEN, self::INFO, self::PURPLE, self::YELLOW, self::RED];
        $chart->setData([
            'labels' => $names,
            'datasets' => [[
                'data' => self::floatCol($agents, 'cost'),
                'backgroundColor' => array_map(
                    static fn (int $i) => $colors[$i % \count($colors)],
                    array_keys($names),
                ),
            ]],
        ]);
        $chart->setOptions(['maintainAspectRatio' => false, 'layout' => ['padding' => 6]]);

        return $chart;
    }

    /**
     * Dwell seconds and entry counts live on two separate x-axes so neither
     * scale flattens the other.
     *
     * @param array<string, StateRow> $states
     */
    public function stateChart(array $states): Chart
    {
        $dwell = self::dataset('Avg dwell (s)', array_map(
            static fn ($v) => -1 === (int) $v ? null : (int) $v,
            self::col($states, 'avg_dwell_s'),
        ), self::YELLOW);
        $entries = self::dataset('Entries', self::col($states, 'entries'), self::RED);
        $dwell['xAxisID'] = 'x';
        $entries['xAxisID'] = 'x1';

        $chart = new Chart(Chart::TYPE_BAR);
        $chart->setData([
            'labels' => array_keys($states),
            'datasets' => [$dwell, $entries],
        ]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'indexAxis' => 'y',
            'layout' => ['padding' => 6],
            'scales' => [
                'x' => ['type' => 'linear'],
                'x1' => ['type' => 'linear', 'position' => 'top', 'grid' => ['display' => false]],
            ],
        ]);

        return $chart;
    }

    /**
     * Tokens per runBreakdown() group, stacked by kind; the $top groups with
     * the most tokens, the rest folded into one "other" bar.
     *
     * @param array<string, BreakdownRow> $groups as runBreakdown() returns them (sorted by total tokens)
     */
    public function breakdownChart(array $groups, int $top = self::TOP): Chart
    {
        $groups = self::foldTail($groups, $top);

        return $this->bar(
            array_map('strval', array_keys($groups)),
            [
                self::dataset('Input', self::col($groups, 'tokens_in'), self::BLUE),
                self::dataset('Output', self::col($groups, 'tokens_out'), self::GREEN),
                self::dataset('Cache read', self::col($groups, 'cache_read'), self::INFO),
                self::dataset('Cache write', self::col($groups, 'cache_write'), self::PURPLE),
            ],
            null,
            true,
            true,
        );
    }

    /**
     * Total tokens per model (bottom axis) and cost where a run reported one
     * (top axis; a model with no reported cost has no cost bar, not a zero).
     *
     * @param array<string, BreakdownRow> $models runBreakdown($events, 'model')
     */
    public function modelChart(array $models, int $top = self::TOP): Chart
    {
        $models = self::foldTail($models, $top);
        $tokens = self::dataset('Total tokens', self::col($models, 'tokens_total'), self::BLUE);
        $tokens['xAxisID'] = 'x';
        $datasets = [$tokens];
        $scales = ['x' => ['type' => 'linear']];
        $costs = array_map(
            static fn (array $row): ?float => null === $row['cost'] ? null : Numbers::float($row['cost']),
            array_values($models),
        );
        if ([] !== array_filter($costs, static fn (?float $c): bool => null !== $c)) {
            $cost = self::dataset('Cost', $costs, self::YELLOW);
            $cost['xAxisID'] = 'x1';
            $datasets[] = $cost;
            $scales['x1'] = ['type' => 'linear', 'position' => 'top', 'grid' => ['display' => false]];
        }

        $chart = new Chart(Chart::TYPE_BAR);
        $chart->setData(['labels' => array_map('strval', array_keys($models)), 'datasets' => $datasets]);
        $chart->setOptions([
            'maintainAspectRatio' => false,
            'indexAxis' => 'y',
            'layout' => ['padding' => 6],
            'scales' => $scales,
        ]);

        return $chart;
    }

    /**
     * Keeps the first $top groups and sums the rest into one "other" group
     * (cost stays null unless some folded group reported one).
     *
     * @param array<string, BreakdownRow> $groups
     *
     * @return array<string, BreakdownRow>
     */
    public static function foldTail(array $groups, int $top = self::TOP): array
    {
        if ($top <= 0 || \count($groups) <= $top) {
            return $groups;
        }
        $kept = \array_slice($groups, 0, $top, true);
        $other = ['runs' => 0, 'duration_s' => 0, 'avg_run_s' => 0, 'tokens_in' => 0, 'tokens_out' => 0, 'cache_read' => 0, 'cache_write' => 0, 'tokens_total' => 0, 'cost' => null];
        foreach (\array_slice($groups, $top, null, true) as $row) {
            foreach (['runs', 'duration_s', 'tokens_in', 'tokens_out', 'cache_read', 'cache_write', 'tokens_total'] as $key) {
                $other[$key] += $row[$key];
            }
            if (null !== $row['cost']) {
                $other['cost'] = round(($other['cost'] ?? 0.0) + $row['cost'], 4);
            }
        }
        $other['avg_run_s'] = $other['runs'] > 0 ? (int) round($other['duration_s'] / $other['runs']) : 0;
        $kept[self::OTHER] = $other;

        return $kept;
    }

    // ------------------------------------------------------------ helpers ---

    /**
     * @param list<string>                                                           $labels
     * @param list<array{label: string, data: list<mixed>, backgroundColor: string}> $datasets
     * @param array<string, mixed>|null                                              $yScale
     */
    private function bar(array $labels, array $datasets, ?array $yScale = null, bool $stacked = false, bool $horizontal = false): Chart
    {
        $options = ['maintainAspectRatio' => false, 'layout' => ['padding' => 6]];
        if ($stacked) {
            $options['scales'] = ['x' => ['stacked' => true], 'y' => ['stacked' => true]];
        } elseif (null !== $yScale) {
            $options['scales'] = ['y' => $yScale];
        }
        if ($horizontal) {
            $options['indexAxis'] = 'y';
        }

        $chart = new Chart(Chart::TYPE_BAR);
        $chart->setData(['labels' => $labels, 'datasets' => $datasets]);
        $chart->setOptions($options);

        return $chart;
    }

    /**
     * @param list<mixed> $data
     *
     * @return array{label: string, data: list<mixed>, backgroundColor: string}
     */
    private static function dataset(string $label, array $data, string $color): array
    {
        return ['label' => $label, 'data' => $data, 'backgroundColor' => $color];
    }

    /**
     * @param array<string, AgentRow|StateRow|BreakdownRow> $rows
     *
     * @return list<int>
     */
    private static function col(array $rows, string $key): array
    {
        return array_map(static fn (array $row) => Numbers::int($row[$key] ?? 0), array_values($rows));
    }

    /**
     * @param array<string, AgentRow|StateRow> $rows
     *
     * @return list<float>
     */
    private static function floatCol(array $rows, string $key): array
    {
        return array_map(static fn (array $row) => Numbers::float($row[$key] ?? 0), array_values($rows));
    }
}
