<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime\ClaudeCode;

use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeUsage;
use Pablo\Tests\TempDir;
use PHPUnit\Framework\TestCase;

/**
 * Token harvesting from Claude Code transcripts, on fixtures shaped like the
 * real ones (one assistant API message split over several lines that each
 * repeat its usage; tool results as user turns; metadata rows).
 */
final class ClaudeCodeUsageTest extends TestCase
{
    private const WORKTREE = '/home/u/.pablo/worktrees/acme/wk-45';
    private const SESSION = '0fc45506-985b-4296-be02-a1039fb8be47';

    private string $configDir;

    protected function setUp(): void
    {
        $this->configDir = TempDir::create('claude-usage');
        mkdir($this->configDir.'/projects/'.ClaudeCodeUsage::projectKey(self::WORKTREE), 0o777, true);
    }

    protected function tearDown(): void
    {
        self::removeDir($this->configDir);
    }

    private static function removeDir(string $dir): void
    {
        if (!is_dir($dir) || is_link($dir)) {
            @unlink($dir);

            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ('.' !== $entry && '..' !== $entry) {
                self::removeDir($dir.'/'.$entry);
            }
        }
        @rmdir($dir);
    }

    private function usage(): ClaudeCodeUsage
    {
        return new ClaudeCodeUsage(configDir: $this->configDir);
    }

    /**
     * @param array<string, int> $usageA
     * @param array<string, int> $usageB
     */
    private function writeTranscript(string $session, string $prompt, string $startedAt, array $usageA, array $usageB, string $model = 'claude-sonnet-5-5'): void
    {
        $rows = [
            ['type' => 'agent-setting', 'agentSetting' => 'task-analyst', 'sessionId' => $session],
            ['type' => 'user', 'timestamp' => $startedAt, 'isSidechain' => false, 'message' => ['role' => 'user', 'content' => $prompt]],
            // One API message (msg_a) streamed as thinking + tool_use lines, usage repeated.
            ['type' => 'assistant', 'timestamp' => $startedAt, 'message' => ['id' => 'msg_a', 'model' => $model, 'content' => [['type' => 'thinking']], 'usage' => $usageA]],
            ['type' => 'assistant', 'timestamp' => $startedAt, 'message' => ['id' => 'msg_a', 'model' => $model, 'content' => [['type' => 'tool_use']], 'usage' => $usageA]],
            ['type' => 'user', 'timestamp' => $startedAt, 'message' => ['role' => 'user', 'content' => [['type' => 'tool_result', 'content' => 'ok']]]],
            ['type' => 'assistant', 'timestamp' => '2026-11-02T10:00:03.500Z', 'message' => ['id' => 'msg_b', 'model' => $model, 'content' => [['type' => 'text', 'text' => 'done']], 'usage' => $usageB]],
            ['type' => 'cost-state', 'sessionId' => $session, 'totalCostUSD' => 0.42],
        ];
        $path = $this->configDir.'/projects/'.ClaudeCodeUsage::projectKey(self::WORKTREE).'/'.$session.'.jsonl';
        file_put_contents($path, implode("\n", array_map(static fn (array $r): string => json_encode($r, \JSON_THROW_ON_ERROR), $rows))."\n");
    }

    public function testProjectKeyMatchesClaudeCodeLayout(): void
    {
        $this->assertSame('-home-u--pablo-worktrees-acme-wk-45', ClaudeCodeUsage::projectKey(self::WORKTREE));
        $this->assertSame('-tmp-pablo-cc-q1xK', ClaudeCodeUsage::projectKey('/tmp/pablo-cc-q1xK/'));
    }

    public function testFingerprintPicksTheRunExactlyAndCountsEachMessageOnce(): void
    {
        $this->writeTranscript(self::SESSION, 'Analyze issue #45', '2026-11-02T10:00:02.000Z',
            ['input_tokens' => 2, 'output_tokens' => 141, 'cache_read_input_tokens' => 0, 'cache_creation_input_tokens' => 2243],
            ['input_tokens' => 2, 'output_tokens' => 265, 'cache_read_input_tokens' => 2243, 'cache_creation_input_tokens' => 211],
        );
        $this->writeTranscript('99999999-2222-4333-8444-555555555555', 'something else', '2026-11-02T10:00:04.000Z',
            ['input_tokens' => 1000, 'output_tokens' => 1000], ['input_tokens' => 1000, 'output_tokens' => 1000], 'claude-haiku-5-5',
        );

        $snapshot = $this->usage()->harvest(self::WORKTREE, '2026-11-02T10:00:00+00:00', \Pablo\Analytics\PromptFingerprint::of('Analyze issue #45'));
        $this->assertNotNull($snapshot);
        $this->assertSame('exact', $snapshot->quality);
        $this->assertSame(self::SESSION, $snapshot->sessionId);
        $this->assertSame(4, $snapshot->input);
        $this->assertSame(406, $snapshot->output);
        $this->assertSame(2243, $snapshot->cacheRead);
        $this->assertSame(2454, $snapshot->cacheWrite);
        $this->assertSame(4 + 406 + 2243 + 2454, $snapshot->totalTokens);
        $this->assertSame(0, $snapshot->reasoning);
        // Cost is not measured for Claude Code (subscription runs have no price).
        $this->assertSame(0.0, $snapshot->cost);
        $this->assertSame(['claude-sonnet-5-5'], $snapshot->models);
        $this->assertSame(1500, $snapshot->spanMs);
    }

    public function testWithoutFingerprintTheWindowIsMerged(): void
    {
        $this->writeTranscript(self::SESSION, 'a', '2026-11-02T10:00:02.000Z', ['input_tokens' => 1, 'output_tokens' => 1], ['input_tokens' => 1, 'output_tokens' => 1]);
        $this->writeTranscript('99999999-2222-4333-8444-555555555555', 'b', '2026-11-02T10:00:04.000Z', ['input_tokens' => 10, 'output_tokens' => 10], ['input_tokens' => 10, 'output_tokens' => 10], 'claude-haiku-5-5');

        $snapshot = $this->usage()->harvest(self::WORKTREE, '2026-11-02T10:00:00+00:00', null);
        $this->assertNotNull($snapshot);
        $this->assertSame('window', $snapshot->quality);
        $this->assertSame(22, $snapshot->input);
        $this->assertSame(['claude-haiku-5-5', 'claude-sonnet-5-5'], $snapshot->models);
        $this->assertSame(self::SESSION.',99999999-2222-4333-8444-555555555555', $snapshot->sessionId);
    }

    public function testRunsBeforeTheLaunchAreIgnored(): void
    {
        $this->writeTranscript(self::SESSION, 'old', '2026-11-01T10:00:00.000Z', ['input_tokens' => 1], ['input_tokens' => 1]);

        $this->assertNull($this->usage()->harvest(self::WORKTREE, '2026-11-02T10:00:00+00:00', null));
        $this->assertNull($this->usage()->harvest('/elsewhere', '2026-11-01T00:00:00+00:00', null));
    }

    public function testUnmatchedFingerprintFallsBackToTheWindow(): void
    {
        $this->writeTranscript(self::SESSION, 'a', '2026-11-02T10:00:02.000Z', ['input_tokens' => 1], ['input_tokens' => 1]);

        $snapshot = $this->usage()->harvest(self::WORKTREE, '2026-11-02T10:00:00+00:00', 'deadbeefdeadbeef');
        $this->assertNotNull($snapshot);
        $this->assertSame('window', $snapshot->quality);
    }
}
