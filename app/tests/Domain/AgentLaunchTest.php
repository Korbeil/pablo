<?php

declare(strict_types=1);

namespace Pablo\Tests\Domain;

use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use PHPUnit\Framework\TestCase;

final class AgentLaunchTest extends TestCase
{
    public function testRoundTripsReconciliationState(): void
    {
        $launch = new AgentLaunch(
            Agent::CiAnalyst,
            '2026-08-01T00:00:00+00:00',
            2,
            '2026-08-01T00:20:00+00:00',
            runId: 'abc123',
            reported: true,
        );
        $data = $launch->toJson();
        $this->assertSame('abc123', $data['run_id']);
        $this->assertTrue($data['reported']);

        $back = AgentLaunch::fromJson('ci-analyst', $data);
        $this->assertSame(Agent::CiAnalyst, $back->agent);
        $this->assertSame($launch->launchedAt, $back->launchedAt);
        $this->assertSame(2, $back->attempts);
        $this->assertSame($launch->finishedAt, $back->finishedAt);
        $this->assertSame('abc123', $back->runId);
        $this->assertTrue($back->reported);
    }

    public function testLegacyJsonWithoutNewKeysLoadsDefaults(): void
    {
        // Task JSONs written before reconciliation existed carry neither key.
        $legacy = AgentLaunch::fromJson('task-analyst', [
            'launched_at' => '2026-07-01T09:00:00+00:00',
            'attempts' => 3,
            'finished_at' => null,
        ]);
        $this->assertNull($legacy->runId);
        $this->assertFalse($legacy->reported);
    }

    public function testWhereTheRunLivesAndHowItEndedRoundTrip(): void
    {
        $launch = (new AgentLaunch(Agent::TaskAnalyst, '2026-08-01T00:00:00+00:00', 1))
            ->withRunId('r1')
            ->withHandle('term_9', 'orca', 'opencode')
            ->stopped('2026-08-01T01:00:00+00:00', AgentLaunch::TIMED_OUT);
        $data = $launch->toJson();
        $this->assertSame(['term_9', 'orca', 'opencode', 'timed-out'], [$data['handle'] ?? null, $data['ade'] ?? null, $data['runtime'] ?? null, $data['stop_reason'] ?? null]);
        $this->assertFalse($data['reported'], 'a stopped run is still to report');

        $back = AgentLaunch::fromJson('task-analyst', $data)->reported('2026-08-01T01:00:00+00:00', 'r1');
        $this->assertSame('term_9', $back->handle);
        $this->assertSame('orca', $back->ade);
        $this->assertSame('opencode', $back->runtime);
        $this->assertSame(AgentLaunch::TIMED_OUT, $back->stopReason);
        $this->assertSame('2026-08-01T01:00:00+00:00', $back->finishedAt);
        $this->assertTrue($back->reported);
    }

    public function testUnknownHandleKeysStayOutOfTheJson(): void
    {
        $data = (new AgentLaunch(Agent::CiAnalyst, null, 1))->toJson();

        $this->assertSame(['launched_at', 'attempts', 'finished_at', 'run_id', 'reported'], array_keys($data));
    }
}
