<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Symfony\UX\Chartjs\Model\Chart;

/**
 * Builds the six /analytics charts from AnalyticsAggregator output. Pure
 * data-in, Chart-objects-out: no reads, no clock — the controller owns both,
 * exactly like the terminal side (StatsCommand owns filtering, the
 * aggregator owns numbers).
 *
 * @phpstan-type AgentRow array<string, float|int|string>
 * @phpstan-type StateRow array<string, float|int|string>
 */
final class AnalyticsCharts
{
    // Bulma-aligned palette.
    private const BLUE = '#485fc7';
    private const GREEN = '#48c78e';
    private const INFO = '#3e8ed0';
    private const PURPLE = '#8a4fd3';
    private const YELLOW = '#ffd970';
    private const RED = '#f14668';

    public function __construct()
    {
    }

    /** @param list<array{date: string, opened: int, closed: int}> $daily */
    public function throughputChart(array $daily): Chart
    {
        return $this->bar(
            array_map(static fn (array $d) => substr((string) $d['date'], 5), $daily),
            [
                self::dataset('Opened', array_map(static fn (array $d) => (int) $d['opened'], $daily), self::BLUE),
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
     * @param array<string, AgentRow|StateRow> $rows
     *
     * @return list<int>
     */
    private static function col(array $rows, string $key): array
    {
        return array_map(static fn (array $row) => self::intOf($row[$key] ?? 0), array_values($rows));
    }

    /**
     * @param array<string, AgentRow|StateRow> $rows
     *
     * @return list<float>
     */
    private static function floatCol(array $rows, string $key): array
    {
        return array_map(static fn (array $row) => self::floatOf($row[$key] ?? 0), array_values($rows));
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
