<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\TaskSummarizer;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class TaskSummarizerTest extends TestCase
{
    protected function setUp(): void
    {
        // Pin the runtime: the developer's own ~/.pablo/config.yaml must not leak in.
        putenv('PABLO_AGENT_RUNTIME=opencode');
    }

    protected function tearDown(): void
    {
        putenv('PABLO_AGENT_RUNTIME');
    }

    public function testSummarizesViaClaudeHaikuUnderClaudeCode(): void
    {
        putenv('PABLO_AGENT_RUNTIME=claude-code');
        $runner = new FakeProcessRunner();
        $runner->outputs = [json_encode(['type' => 'result', 'is_error' => false, 'session_id' => 'x', 'result' => "Fix webhook retry with backoff.\n"], \JSON_THROW_ON_ERROR)];
        $summarizer = new TaskSummarizer($runner);

        $this->assertSame('Fix webhook retry with backoff', $summarizer->summarize('/repo', 'do the webhooks'));

        $this->assertCount(1, $runner->calls, 'nothing persisted, so nothing to delete');
        [$argv, $check, $timeout, $cwd] = $runner->calls[0];
        $this->assertSame(['claude', '-p', '--model', 'haiku', '--output-format', 'json', '--no-session-persistence', '--strict-mcp-config', '--tools', ''], \array_slice($argv, 0, 10));
        $this->assertSame('--', $argv[10]);
        $this->assertStringContainsString('do the webhooks', (string) end($argv));
        $this->assertFalse($check);
        $this->assertSame('/repo', $cwd);
    }

    public function testClaudeErrorResultFallsBack(): void
    {
        $this->assertNull(TaskSummarizer::parseClaude('{"type":"result","is_error":true,"result":"Not logged in"}'));
        $this->assertNull(TaskSummarizer::parseClaude('not json'));
        $this->assertSame('ok', TaskSummarizer::parseClaude('{"result":"ok"}'));
    }

    public function testSummarizesViaOpencodeBigPickle(): void
    {
        $runner = new FakeProcessRunner();
        $runner->outputs = [self::validEvents('fix webhook retry handling with backoff')];
        $summarizer = new TaskSummarizer($runner);

        $this->assertSame('fix webhook retry handling with backoff', $summarizer->summarize('/repo', 'do the webhooks'));

        [$argv, $check, $timeout, $cwd] = $runner->calls[0];
        $this->assertSame('opencode', $argv[0]);
        $this->assertSame('run', $argv[1]);
        $this->assertSame('opencode/big-pickle', $argv[3]);
        $this->assertNotContains('--dir', $argv);
        $this->assertStringContainsString('do the webhooks', (string) end($argv));
        $this->assertFalse($check);
        $this->assertSame(60.0, $timeout);
        $this->assertSame('/repo', $cwd);

        [$delArgv] = $runner->calls[1];
        $this->assertSame(['opencode', 'session', 'delete', 'ses_test'], $delArgv);
    }

    public function testDeletesSessionEvenWhenAnswerIsUnusable(): void
    {
        $runner = new FakeProcessRunner();
        $runner->outputs = [self::validEvents('')];
        $summarizer = new TaskSummarizer($runner);

        $this->assertNull($summarizer->summarize('/repo', 'do the webhooks'));
        $this->assertCount(2, $runner->calls);
        [$argv] = $runner->calls[1];
        $this->assertSame(['opencode', 'session', 'delete', 'ses_test'], $argv);
    }

    public function testReturnsNullWhenRunnerThrows(): void
    {
        $runner = new FakeProcessRunner();
        $runner->onRun = static fn (): string => throw new \RuntimeException('boom');
        $summarizer = new TaskSummarizer($runner);

        $this->assertNull($summarizer->summarize('/repo', 'do the webhooks'));
    }

    public function testCleanCapsAtSevenWords(): void
    {
        $this->assertSame('one two three four five six seven', TaskSummarizer::clean('one two three four five six seven eight nine'));
    }

    public function testCleanStripsQuotesAndTrailingPunctuation(): void
    {
        $this->assertSame('fix webhooks retry', TaskSummarizer::clean('"fix webhooks retry."'));
    }

    public function testCleanNullOnWhitespaceOnly(): void
    {
        $this->assertNull(TaskSummarizer::clean("  \n  "));
    }

    public function testCleanDropsAnsiNoise(): void
    {
        $this->assertSame('fix webhooks retry', TaskSummarizer::clean("\x1b[0m\nfix webhooks retry.\x1b[0m"));
    }

    public function testParseExtractsTextAndSessionId(): void
    {
        $parsed = TaskSummarizer::parse(self::validEvents('fix webhooks retry.'));

        $this->assertSame('fix webhooks retry.', $parsed['text']);
        $this->assertSame('ses_test', $parsed['session']);
    }

    public function testParseSkipsNoiseAndErrorEvents(): void
    {
        $raw = "not json\n\n"
            .json_encode(['type' => 'error', 'sessionID' => 'ses_err'])."\n"
            .json_encode(['type' => 'step_start', 'sessionID' => 'ses_test', 'part' => ['type' => 'step-start']])."\n"
            .json_encode(['type' => 'text', 'sessionID' => 'ses_test', 'part' => ['type' => 'text', 'text' => 'hi']]);

        $parsed = TaskSummarizer::parse($raw);

        $this->assertSame('ses_test', $parsed['session']);
        $this->assertSame('hi', $parsed['text']);
    }

    /** Minimal real-shape opencode JSON event stream, as observed from the CLI. */
    private static function validEvents(string $text): string
    {
        return json_encode([
            'type' => 'step_start', 'sessionID' => 'ses_test', 'part' => ['type' => 'step-start'],
        ])."\n"
            .json_encode([
                'type' => 'text', 'sessionID' => 'ses_test', 'part' => ['type' => 'text', 'text' => $text],
            ]);
    }
}
