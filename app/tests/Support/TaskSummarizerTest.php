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
        $runner->outputs = ['fix webhook retry handling with backoff'];
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
}
