<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\OpenCodeUsage;
use Pablo\Domain\Time;
use Pablo\Support\ProbeResult;
use Pablo\Support\ProcessRunnerInterface;
use PHPUnit\Framework\TestCase;

final class OpenCodeUsageTest extends TestCase
{
    private const LAUNCHED = '2026-08-20T10:00:00+00:00';

    public function testExactMatchByPromptFingerprint(): void
    {
        $baseMs = (int) (new \DateTimeImmutable(self::LAUNCHED))->format('U') * 1000;
        $usage = $this->usage(static function () use ($baseMs): ProbeResult {
            return new ProbeResult(0, self::sessionListPayload($baseMs));
        }, static fn (string $id): ProbeResult => new ProbeResult(0, self::exportPayload($id, $baseMs)));

        $snapshot = $usage->harvest('/wt', self::LAUNCHED, substr(hash('sha256', 'hello world'), 0, 16));

        $this->assertNotNull($snapshot);
        $this->assertSame('exact', $snapshot->quality);
        $this->assertSame('ses_a', $snapshot->sessionId);
        $this->assertSame(100, $snapshot->input);
        $this->assertSame(50, $snapshot->output);
        $this->assertSame(10, $snapshot->reasoning);
        $this->assertSame(300, $snapshot->cacheRead);
        $this->assertSame(20, $snapshot->cacheWrite);
        $this->assertSame(480, $snapshot->totalTokens);
        $this->assertSame(0.4, $snapshot->cost);
        $this->assertSame(['anthropic/claude'], $snapshot->models);
        $this->assertSame(30_000, $snapshot->spanMs);
    }

    public function testWindowMergeWithoutFingerprint(): void
    {
        $baseMs = (int) (new \DateTimeImmutable(self::LAUNCHED))->format('U') * 1000;
        $usage = $this->usage(static function () use ($baseMs): ProbeResult {
            return new ProbeResult(0, self::sessionListPayload($baseMs));
        }, static fn (string $id): ProbeResult => new ProbeResult(0, self::exportPayload($id, $baseMs)));

        $snapshot = $usage->harvest('/wt', self::LAUNCHED, null);

        $this->assertNotNull($snapshot);
        $this->assertSame('window', $snapshot->quality);
        // Both sessions in the window are merged; the pre-window and
        // other-directory ones are not.
        $this->assertSame('ses_a,ses_c', $snapshot->sessionId);
        $this->assertSame(300, $snapshot->input);
        $this->assertSame(150, $snapshot->output);
        $this->assertSame(['anthropic/claude', 'openrouter/x'], $snapshot->models);
    }

    public function testNoCandidateSessionsYieldsNull(): void
    {
        $baseMs = (int) (new \DateTimeImmutable(self::LAUNCHED))->format('U') * 1000;
        $usage = $this->usage(
            static fn (): ProbeResult => new ProbeResult(0, self::sessionListPayload($baseMs)),
            static fn (string $id): ProbeResult => new ProbeResult(0, '{}'),
        );

        $this->assertNull($usage->harvest('/nowhere', self::LAUNCHED, null));
    }

    public function testSessionListFailureYieldsNull(): void
    {
        $usage = $this->usage(
            static fn (): ProbeResult => new ProbeResult(1, 'boom'),
            static fn (string $id): ProbeResult => new ProbeResult(0, '{}'),
        );

        $this->assertNull($usage->harvest('/wt', self::LAUNCHED, null));
    }

    private function usage(\Closure $onList, \Closure $onExport): OpenCodeUsage
    {
        $runner = new class($onList, $onExport) implements ProcessRunnerInterface {
            /**
             * @param \Closure(): ProbeResult       $onList
             * @param \Closure(string): ProbeResult $onExport
             */
            public function __construct(
                private readonly \Closure $onList,
                private readonly \Closure $onExport,
            ) {
            }

            public function run(array $argv, bool $check = true, ?float $timeout = null): string
            {
                throw new \LogicException('not expected');
            }

            public function runParallel(array $commandGroups, bool $check = true, ?float $timeout = null): array
            {
                throw new \LogicException('not expected');
            }

            public function probe(array $argv, ?float $timeout = null): ProbeResult
            {
                if ('session' === ($argv[1] ?? '')) {
                    return ($this->onList)();
                }
                if ('export' === ($argv[1] ?? '')) {
                    return ($this->onExport)((string) ($argv[2] ?? ''));
                }

                return new ProbeResult(0, '');
            }
        };

        return new OpenCodeUsage($runner, new Time());
    }

    /** @return string JSON */
    private static function sessionListPayload(int $baseMs): string
    {
        $json = json_encode([
            ['id' => 'ses_old', 'directory' => '/wt', 'created' => $baseMs - 60_000],
            ['id' => 'ses_a', 'directory' => '/wt', 'created' => $baseMs + 500],
            ['id' => 'ses_b', 'directory' => '/other', 'created' => $baseMs + 600],
            ['id' => 'ses_c', 'directory' => '/wt/', 'created' => $baseMs + 800],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return $json;
    }

    /** @return string JSON */
    private static function exportPayload(string $sessionId, int $baseMs): string
    {
        $model = 'ses_c' === $sessionId ? 'openrouter/x' : 'anthropic/claude';
        $scale = 'ses_c' === $sessionId ? 1 : 0;
        $json = json_encode([
            'info' => ['id' => $sessionId, 'title' => 'pablo:task-analyst', 'cost' => 0.9 * $scale],
            'messages' => [
                ['info' => ['role' => 'user'], 'parts' => [['type' => 'text', 'text' => 'hello world']]],
                [
                    'info' => [
                        'role' => 'assistant',
                        'modelID' => $model,
                        'providerID' => explode('/', $model)[0],
                        'cost' => 0.4 - 0.4 * $scale,
                        'tokens' => [
                            'input' => 100 + 100 * $scale,
                            'output' => 50 + 50 * $scale,
                            'reasoning' => 10,
                            'total' => 480 + 150 * $scale,
                            'cache' => ['read' => 300 + 300 * $scale, 'write' => 20],
                        ],
                        'time' => ['created' => $baseMs + 1000, 'completed' => $baseMs + 31_000],
                    ],
                    'parts' => [],
                ],
            ],
        ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES);

        return $json;
    }
}
