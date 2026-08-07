<?php

declare(strict_types=1);

namespace Pablo\Tests\Domain;

use Pablo\Domain\PrBadge;
use Pablo\Domain\PrBadgeKind;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Gh\PrInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PrBadgeTest extends TestCase
{
    private function task(bool $merged = false, ?int $prNumber = null): Task
    {
        $task = new Task('p', 'b', '/wt', State::Draft);
        $task->merged = $merged;
        $task->prNumber = $prNumber;

        return $task;
    }

    /** @return iterable<string, array{PrBadge, string}> */
    public static function badges(): iterable
    {
        yield 'none' => [new PrBadge(PrBadgeKind::None), '-'];
        yield 'merged without number' => [new PrBadge(PrBadgeKind::Merged), '✅ merged'];
        yield 'merged with number' => [new PrBadge(PrBadgeKind::Merged, 12), '✅ merged #12'];
        yield 'draft' => [new PrBadge(PrBadgeKind::Draft, 34), '📝 draft #34'];
        yield 'open' => [new PrBadge(PrBadgeKind::Open, 17035), '📖 open #17035'];
        yield 'unknown' => [new PrBadge(PrBadgeKind::Unknown, 56), '#56'];
    }

    #[DataProvider('badges')]
    public function testRendersTheHistoricalCell(PrBadge $badge, string $expected): void
    {
        $this->assertSame($expected, $badge->render());
    }

    /**
     * The poller caches the rendered cell, so the dashboard has to parse our
     * own output back into structure. Every kind must survive the round trip.
     */
    #[DataProvider('badges')]
    public function testRoundTripsThroughDisplay(PrBadge $badge, string $rendered): void
    {
        $parsed = PrBadge::fromDisplay($rendered);
        $this->assertSame($badge->kind, $parsed->kind);
        $this->assertSame($badge->number, $parsed->number);
        $this->assertSame($rendered, $parsed->render());
    }

    public function testFromTaskMergedWins(): void
    {
        $badge = PrBadge::fromTask($this->task(merged: true, prNumber: 9), null);
        $this->assertSame(PrBadgeKind::Merged, $badge->kind);
        $this->assertNull($badge->number);
        $this->assertSame('✅ merged', $badge->render());
    }

    public function testFromTaskWithoutPrNumber(): void
    {
        $this->assertSame(PrBadgeKind::None, PrBadge::fromTask($this->task(), null)->kind);
    }

    public function testFromTaskWithNumberButNoInfo(): void
    {
        $badge = PrBadge::fromTask($this->task(prNumber: 7), null);
        $this->assertSame(PrBadgeKind::Unknown, $badge->kind);
        $this->assertSame(7, $badge->number);
    }

    public function testFromTaskUsesPrInfoState(): void
    {
        $task = $this->task(prNumber: 7);
        $this->assertSame(
            PrBadgeKind::Draft,
            PrBadge::fromTask($task, new PrInfo(number: 7, title: 't', state: 'OPEN', isDraft: true, url: 'u', mergedAt: null))->kind,
        );
        $this->assertSame(
            PrBadgeKind::Open,
            PrBadge::fromTask($task, new PrInfo(number: 7, title: 't', state: 'OPEN', isDraft: false, url: 'u', mergedAt: null))->kind,
        );
        $this->assertSame(
            PrBadgeKind::Merged,
            PrBadge::fromTask($task, new PrInfo(number: 7, title: 't', state: 'MERGED', isDraft: false, url: 'u', mergedAt: null))->kind,
        );
    }

    public function testUnparseableDisplayDegradesToNone(): void
    {
        $this->assertSame(PrBadgeKind::None, PrBadge::fromDisplay('who knows')->kind);
        $this->assertSame(PrBadgeKind::None, PrBadge::fromDisplay('')->kind);
    }

    public function testUrl(): void
    {
        $badge = new PrBadge(PrBadgeKind::Open, 42);
        $this->assertSame('https://github.com/acme/web/pull/42', $badge->url('acme/web'));
        $this->assertNull($badge->url(null));
        $this->assertNull((new PrBadge(PrBadgeKind::Merged))->url('acme/web'));
    }
}
