<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\TaskSummarizer;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

final class TaskSummarizerTest extends TestCase
{
    public function testSummarizesViaOpencodeBigPickle(): void
    {
        $runner = new FakeProcessRunner();
        $runner->outputs = [self::validEvents('fix webhook retry handling with backoff')];
        $summarizer = new TaskSummarizer($runner);

        $this->assertSame('fix webhook retry handling with backoff', $summarizer->summarize('/repo', 'do the webhooks'));

        [$argv, $check, $timeout] = $runner->calls[0];
        $this->assertSame('opencode', $argv[0]);
        $this->assertSame('run', $argv[1]);
        $this->assertSame('opencode/big-pickle', $argv[3]);
        $this->assertStringEndsWith('/repo', $argv[5]);
        $this->assertStringContainsString('do the webhooks', (string) end($argv));
        $this->assertFalse($check);
        $this->assertSame(60.0, $timeout);

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
