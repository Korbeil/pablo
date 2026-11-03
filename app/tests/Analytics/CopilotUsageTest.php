<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\CopilotUsage;
use Pablo\Analytics\OpenCodeUsage;
use PHPUnit\Framework\TestCase;

/**
 * Token harvesting from Copilot CLI session state
 * (session-state/<id>/events.jsonl), on fixtures shaped like its events:
 * cumulative totals on usage checkpoints and on the shutdown event.
 */
final class CopilotUsageTest extends TestCase
{
    private const WORKTREE = '/home/u/.pablo/worktrees/acme/wk-45';
    private const SESSION = '0fc45506-985b-4296-be02-a1039fb8be47';

    private string $home;

    protected function setUp(): void
    {
        $this->home = sys_get_temp_dir().'/pablo-copilot-usage-'.uniqid();
        mkdir($this->home.'/session-state', 0o777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->home.'/session-state/*/events.jsonl') ?: [] as $file) {
            unlink($file);
            rmdir(\dirname($file));
        }
        @rmdir($this->home.'/session-state');
        @rmdir($this->home);
    }

    private function usage(): CopilotUsage
    {
        return new CopilotUsage(configDir: $this->home);
    }

    private function writeSession(string $session, string $prompt, string $startedAt, ?string $cwd = self::WORKTREE): void
    {
        $start = new \DateTimeImmutable($startedAt);
        $at = static fn (int $s): string => $start->modify("+{$s} seconds")->format('Y-m-d\TH:i:s.v\Z');
        $metrics = static fn (int $in, int $out): array => [
            'claude-sonnet-5.5' => ['usage' => ['inputTokens' => $in, 'outputTokens' => $out, 'cacheReadTokens' => 300, 'cacheWriteTokens' => 100, 'reasoningTokens' => 20]],
        ];
        $rows = [
            ['type' => 'session.start', 'timestamp' => $at(0), 'data' => null !== $cwd ? ['context' => ['cwd' => $cwd]] : []],
            ['type' => 'user.message', 'timestamp' => $at(1), 'data' => ['content' => $prompt]],
            ['type' => 'session.usage_checkpoint', 'timestamp' => $at(20), 'data' => ['modelMetrics' => $metrics(500, 50)]],
            'not json',
            ['type' => 'session.shutdown', 'timestamp' => $at(42), 'data' => ['modelMetrics' => $metrics(1400, 250)]],
        ];
        mkdir($this->home.'/session-state/'.$session);
        file_put_contents(
            $this->home.'/session-state/'.$session.'/events.jsonl',
            implode("\n", array_map(static fn (mixed $r): string => \is_string($r) ? $r : (string) json_encode($r), $rows))."\n",
        );
    }

    public function testExactMatchReadsTheLatestCumulativeTotals(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeSession(self::SESSION, 'Analyze issue #45', $now);
        $this->writeSession('11111111-2222-4333-8444-555555555555', 'something else', $now);

        $snapshot = $this->usage()->harvest(self::WORKTREE, $now, OpenCodeUsage::fingerprint('Analyze issue #45'));

        $this->assertNotNull($snapshot);
        $this->assertSame('exact', $snapshot->quality);
        $this->assertSame(self::SESSION, $snapshot->sessionId);
        $this->assertSame(1000, $snapshot->input, 'inputTokens minus the cached ones');
        $this->assertSame(250, $snapshot->output);
        $this->assertSame(300, $snapshot->cacheRead);
        $this->assertSame(100, $snapshot->cacheWrite);
        $this->assertSame(1650, $snapshot->totalTokens);
        $this->assertSame(0.0, $snapshot->cost);
        $this->assertSame(['claude-sonnet-5.5'], $snapshot->models);
        $this->assertSame(42000, $snapshot->spanMs);
    }

    public function testOtherWorktreesAndOlderSessionsAreIgnored(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeSession(self::SESSION, 'p', $now, '/somewhere/else');
        $this->writeSession('11111111-2222-4333-8444-555555555555', 'p', gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

        $this->assertNull($this->usage()->harvest(self::WORKTREE, $now, OpenCodeUsage::fingerprint('p')));
    }

    public function testWithoutFingerprintOnlySessionsKnownToRunInTheWorktreeCount(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeSession(self::SESSION, 'p', $now);
        $this->writeSession('11111111-2222-4333-8444-555555555555', 'p', $now, null);

        $snapshot = $this->usage()->harvest(self::WORKTREE, $now, null);
        $this->assertNotNull($snapshot);
        $this->assertSame('window', $snapshot->quality);
        $this->assertSame(self::SESSION, $snapshot->sessionId);
    }

    public function testNothingToReadIsNull(): void
    {
        $this->assertNull($this->usage()->harvest(self::WORKTREE, gmdate('Y-m-d\TH:i:s\Z'), null));
        $this->assertNull((new CopilotUsage(configDir: '/nonexistent'))->harvest(self::WORKTREE, 'garbage', null));
    }
}
