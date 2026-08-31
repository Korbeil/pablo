<?php

declare(strict_types=1);

namespace Pablo\Tests\Domain;

use Pablo\Agents\SessionInfo;
use Pablo\Domain\AgentActivity;
use PHPUnit\Framework\TestCase;

final class AgentActivityTest extends TestCase
{
    public function testNoSessions(): void
    {
        $a = AgentActivity::fromSessions([]);
        $this->assertSame(0, $a->total);
        $this->assertSame('-', $a->render());
        $this->assertFalse($a->isWaiting());
        $this->assertFalse($a->isRunning());
    }

    public function testCountsAndRenders(): void
    {
        $a = AgentActivity::fromSessions([
            new SessionInfo('h1', 'running'),
            new SessionInfo('h2', 'running'),
            new SessionInfo('h3', 'waiting'),
        ]);
        $this->assertSame(3, $a->total);
        $this->assertSame(2, $a->running);
        $this->assertSame(1, $a->waiting);
        $this->assertSame('🏃 2 · 💭 1', $a->render());
        $this->assertTrue($a->isWaiting());
        $this->assertTrue($a->isRunning());
    }

    public function testWaitingAloneIsNotRunning(): void
    {
        $a = AgentActivity::fromSessions([new SessionInfo('h1', 'waiting')]);
        $this->assertTrue($a->isWaiting());
        $this->assertFalse($a->isRunning());
    }

    /**
     * A session in some other status still counts towards the total but adds
     * no segment, so the rendered cell is empty rather than "-". Long-standing
     * behaviour the terminal listing depends on.
     */
    public function testUnknownStatusCountsButRendersEmpty(): void
    {
        $a = AgentActivity::fromSessions([new SessionInfo('h1', 'idle')]);
        $this->assertSame(1, $a->total);
        $this->assertSame('', $a->render());
    }

    public function testRoundTripsThroughDisplay(): void
    {
        foreach ([
            [0, 0, 0],
            [1, 1, 0],
            [1, 0, 1],
            [3, 2, 1],
            [12, 9, 3],
        ] as [$total, $running, $waiting]) {
            $a = new AgentActivity($total, $running, $waiting);
            $parsed = AgentActivity::fromDisplay($a->total, $a->render());
            $this->assertSame($a->total, $parsed->total);
            $this->assertSame($a->running, $parsed->running);
            $this->assertSame($a->waiting, $parsed->waiting);
        }
    }

    public function testFromDisplayWithNeverPolledCache(): void
    {
        $a = AgentActivity::fromDisplay(null, null);
        $this->assertSame(0, $a->total);
        $this->assertSame('-', $a->render());
    }
}
