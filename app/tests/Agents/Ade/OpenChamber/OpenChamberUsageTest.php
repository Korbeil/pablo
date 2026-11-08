<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\OpenChamber;

use Pablo\Agents\Ade\OpenChamber\OpenChamberUsage;
use Pablo\Domain\Time;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class OpenChamberUsageTest extends TestCase
{
    private const LAUNCHED = '2026-08-20T10:00:00+00:00';

    public function testOpenchamberSessionsAreAggregated(): void
    {
        $baseMs = (int) (new \DateTimeImmutable(self::LAUNCHED))->format('U') * 1000;
        $runner = new FakeProcessRunner();
        $runner->probes = [new ProbeResult(0, self::openchamberListPayload($baseMs))];
        $usage = new OpenChamberUsage($runner, new Time());

        $snapshot = $usage->harvest('/wt', self::LAUNCHED, null);

        $this->assertNotNull($snapshot);
        $this->assertSame('window', $snapshot->quality);
        // Oldest first; the pre-window row is excluded.
        $this->assertSame('ses_a,ses_b', $snapshot->sessionId);
        $this->assertSame(300, $snapshot->input);
        $this->assertSame(75, $snapshot->output);
        $this->assertSame(30, $snapshot->reasoning);
        $this->assertSame(330, $snapshot->cacheRead);
        $this->assertSame(40, $snapshot->cacheWrite);
        // OpenCode's total is the sum of all components.
        $this->assertSame(775, $snapshot->totalTokens);
        $this->assertSame(1.5, $snapshot->cost);
        $this->assertSame(['anthropic/claude', 'opencode-go/ox-alpha-free'], $snapshot->models);
        // max(updated) - min(created)
        $this->assertSame(51_500, $snapshot->spanMs);
    }

    public function testDaemonFailureYieldsNull(): void
    {
        $runner = new FakeProcessRunner();
        $runner->probes = [new ProbeResult(127, 'openchamber: command not found')];

        $this->assertNull((new OpenChamberUsage($runner, new Time()))->harvest('/wt', self::LAUNCHED, null));
    }

    public function testProbeAsksForArchivedSessions(): void
    {
        $runner = new FakeProcessRunner();
        (new OpenChamberUsage($runner, new Time()))->harvest('/wt', self::LAUNCHED, null);

        $this->assertCount(1, $runner->probeCalls);
        $this->assertSame(['openchamber', 'session', 'list', '--dir', '/wt'], \array_slice($runner->probeCalls[0], 0, 5));
        $this->assertContains('--all', $runner->probeCalls[0]);
    }

    /** @return string JSON */
    private static function openchamberListPayload(int $baseMs): string
    {
        $json = json_encode([
            'status' => 'ok',
            'sessions' => [
                [
                    'id' => 'ses_old',
                    'directory' => '/wt',
                    'title' => 'pablo:task-analyst',
                    'cost' => 9.99,
                    'tokens' => ['input' => 1, 'output' => 1, 'reasoning' => 0, 'cache' => ['read' => 1, 'write' => 1]],
                    'model' => ['id' => 'x', 'providerID' => 'y'],
                    'time' => ['created' => $baseMs - 60_000, 'updated' => $baseMs - 30_000],
                ],
                [
                    'id' => 'ses_a',
                    'directory' => '/wt',
                    'title' => 'pablo:task-analyst',
                    'cost' => 0,
                    'tokens' => ['input' => 100, 'output' => 50, 'reasoning' => 10, 'cache' => ['read' => 300, 'write' => 20]],
                    'model' => ['id' => 'claude', 'providerID' => 'anthropic'],
                    'time' => ['created' => $baseMs + 500, 'updated' => $baseMs + 31_000],
                ],
                [
                    'id' => 'ses_b',
                    'directory' => '/wt',
                    'title' => 'pablo:ci-analyst',
                    'cost' => 1.5,
                    'tokens' => ['input' => 200, 'output' => 25, 'reasoning' => 20, 'cache' => ['read' => 30, 'write' => 20]],
                    'model' => ['id' => 'ox-alpha-free', 'providerID' => 'opencode-go'],
                    'time' => ['created' => $baseMs + 600, 'updated' => $baseMs + 52_000],
                ],
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return $json;
    }
}
