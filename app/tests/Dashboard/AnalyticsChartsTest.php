<?php

declare(strict_types=1);

namespace Pablo\Tests\Dashboard;

use Pablo\Dashboard\AnalyticsCharts;
use PHPUnit\Framework\TestCase;

final class AnalyticsChartsTest extends TestCase
{
    /**
     * @return array{runs: int, duration_s: int, avg_run_s: int, tokens_in: int, tokens_out: int, cache_read: int, cache_write: int, tokens_total: int, cost: float|null}
     */
    private static function row(int $tokens, ?float $cost = null, int $runs = 1): array
    {
        return ['runs' => $runs, 'duration_s' => 60 * $runs, 'avg_run_s' => 60, 'tokens_in' => $tokens, 'tokens_out' => 0, 'cache_read' => 0, 'cache_write' => 0, 'tokens_total' => $tokens, 'cost' => $cost];
    }

    public function testFoldTailKeepsTheTopGroupsAndSumsTheRest(): void
    {
        $groups = ['a' => self::row(500), 'b' => self::row(300, 0.5), 'c' => self::row(200), 'd' => self::row(100, 0.25, 3)];

        $folded = AnalyticsCharts::foldTail($groups, 2);

        $this->assertSame(['a', 'b', AnalyticsCharts::OTHER], array_keys($folded));
        $other = $folded[AnalyticsCharts::OTHER];
        $this->assertSame(4, $other['runs']);
        $this->assertSame(300, $other['tokens_total']);
        $this->assertSame(0.25, $other['cost']);
        $this->assertSame(60, $other['avg_run_s']);
        $this->assertSame($groups, AnalyticsCharts::foldTail($groups, 4), 'nothing to fold');
    }

    public function testBreakdownChartIsAHorizontalStackOfTokenKinds(): void
    {
        $chart = (new AnalyticsCharts())->breakdownChart(['x' => self::row(10), 'y' => self::row(5)]);

        $this->assertSame('y', $chart->getOptions()['indexAxis']);
        $this->assertTrue($chart->getOptions()['scales']['x']['stacked']);
        $this->assertSame(['x', 'y'], $chart->getData()['labels']);
        $this->assertSame(['Input', 'Output', 'Cache read', 'Cache write'], array_column($chart->getData()['datasets'], 'label'));
    }

    public function testModelChartDrawsCostOnlyWhereReported(): void
    {
        $charts = new AnalyticsCharts();

        $withCost = $charts->modelChart(['m1' => self::row(10, 1.5), 'm2' => self::row(5)]);
        $datasets = $withCost->getData()['datasets'];
        $this->assertSame(['Total tokens', 'Cost'], array_column($datasets, 'label'));
        $this->assertSame([1.5, null], $datasets[1]['data'], 'unknown cost is no bar, not a zero');

        $noCost = $charts->modelChart(['m1' => self::row(10)]);
        $this->assertSame(['Total tokens'], array_column($noCost->getData()['datasets'], 'label'));
        $this->assertArrayNotHasKey('x1', $noCost->getOptions()['scales']);
    }
}
