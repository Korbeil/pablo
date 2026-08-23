<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\JsonlAnalytics;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use PHPUnit\Framework\TestCase;

final class JsonlAnalyticsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir().'/pablo-analytics-'.uniqid();
        putenv('PABLO_ANALYTICS_DIR='.$this->root);
    }

    protected function tearDown(): void
    {
        putenv('PABLO_ANALYTICS_DIR');
        exec('rm -rf '.escapeshellarg($this->root));
    }

    private function task(): Task
    {
        $task = new Task('proj', 'br-1', '/tmp/wt', State::InProgress, prompt: 'fix the thing');
        $task->issue = new Issue('github', '45', 'https://x/45', 'T', 'PR');

        return $task;
    }

    public function testTaskOpenedWritesOneJsonlLinePerMonthFile(): void
    {
        $analytics = new JsonlAnalytics();
        $analytics->taskOpened($this->task());

        $file = $this->root.'/proj/'.gmdate('Y-m').'.jsonl';
        $this->assertFileExists($file);
        $lines = file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $this->assertCount(1, $lines);
        /** @var array<string, mixed> $event */
        $event = json_decode((string) $lines[0], true);
        $this->assertSame('task_opened', $event['type']);
        $this->assertSame('proj', $event['project']);
        $this->assertSame('br-1', $event['branch']);
        $this->assertSame('in-progress', $event['initial_state']);
        $this->assertSame('github', $event['issue_provider']);
        $this->assertSame('45', $event['issue_key']);
        $this->assertSame(mb_strlen('fix the thing'), $event['prompt_len']);
        $this->assertSame('/tmp/wt', $event['worktree']);
        $this->assertArrayHasKey('ts', $event);
        // No prompt content is ever persisted.
        $this->assertStringNotContainsString('fix the thing', (string) $lines[0]);
    }

    public function testEventsAppendAcrossTypesAndProjects(): void
    {
        $analytics = new JsonlAnalytics();
        $task = $this->task();
        $analytics->taskOpened($task);
        $analytics->stateEntered($task, State::InProgress);
        $analytics->agentRunStarted('other-proj', 'b2', '/wt2', 'ci-analyst', 'orca', 'run123', '2026-08-01T00:00:00+00:00', 'secret prompt text');

        $projLines = file($this->root.'/proj/'.gmdate('Y-m').'.jsonl', \FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(2, $projLines);
        $second = json_decode((string) $projLines[1], true);
        $this->assertSame('state_entered', $second['type']);
        $this->assertSame('in-progress', $second['from']);
        $this->assertSame('in-progress', $second['to']);

        $otherLines = file($this->root.'/other-proj/'.gmdate('Y-m').'.jsonl', \FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertCount(1, $otherLines);
        $started = json_decode((string) $otherLines[0], true);
        $this->assertSame('agent_run_started', $started['type']);
        $this->assertSame('run123', $started['run_id']);
        $this->assertSame('ci-analyst', $started['agent']);
        $this->assertSame('orca', $started['backend']);
        $this->assertSame(substr(hash('sha256', 'secret prompt text'), 0, 16), $started['prompt_fingerprint']);
        $this->assertStringNotContainsString('secret prompt text', (string) $otherLines[0]);
    }

    public function testAgentRunFinishedFlattensUsage(): void
    {
        $analytics = new JsonlAnalytics();
        $usage = new \Pablo\Analytics\UsageSnapshot(
            input: 100,
            output: 50,
            reasoning: 10,
            cacheRead: 300,
            cacheWrite: 20,
            totalTokens: 480,
            cost: 0.5,
            models: ['anthropic/claude'],
            sessionId: 'ses_a',
            spanMs: 42_000,
            quality: 'exact',
        );
        $analytics->agentRunFinished(new \Pablo\Analytics\AgentRunRecord(
            project: 'proj',
            branch: 'br-1',
            runId: 'r1',
            agent: 'task-analyst',
            backend: 'openchamber',
            startedAt: '2026-08-01T00:00:00+00:00',
            finishedAt: '2026-08-01T00:30:00+00:00',
            durationS: 1800,
            usage: $usage,
        ));

        $lines = file($this->root.'/proj/'.gmdate('Y-m').'.jsonl', \FILE_IGNORE_NEW_LINES) ?: [];
        /** @var array<string, mixed> $event */
        $event = json_decode((string) $lines[0], true);
        $this->assertSame('agent_run_finished', $event['type']);
        $this->assertSame(1800, $event['duration_s']);
        $this->assertSame(100, $event['input']);
        $this->assertSame(50, $event['output']);
        $this->assertSame(10, $event['reasoning']);
        $this->assertSame(300, $event['cache_read']);
        $this->assertSame(20, $event['cache_write']);
        $this->assertSame(480, $event['tokens_total']);
        $this->assertSame(['anthropic/claude'], $event['models']);
        $this->assertSame('ses_a', $event['session_id']);
        $this->assertSame('exact', $event['harvest']);
        $this->assertSame('openchamber', $event['backend']);
    }

    public function testTaskClosedCarriesFinalMetadata(): void
    {
        $analytics = new JsonlAnalytics();
        $task = $this->task();
        $task->merged = true;
        $task->prNumber = 7;
        $task->agentLaunches['task-analyst'] = new \Pablo\Domain\AgentLaunch(\Pablo\Domain\Agent::TaskAnalyst, '2026-08-01T00:00:00+00:00', 3);
        $analytics->taskClosed($task);

        $lines = file($this->root.'/proj/'.gmdate('Y-m').'.jsonl', \FILE_IGNORE_NEW_LINES) ?: [];
        /** @var array<string, mixed> $event */
        $event = json_decode((string) $lines[0], true);
        $this->assertSame('task_closed', $event['type']);
        $this->assertSame('in-progress', $event['final_state']);
        $this->assertTrue($event['merged']);
        $this->assertSame(7, $event['pr_number']);
        $this->assertSame(['task-analyst' => 3], $event['agent_runs']);
        $this->assertArrayHasKey('opened_at', $event);
        $this->assertArrayHasKey('duration_s', $event);
    }

    public function testRecordingNeverThrowsWhenRootIsUnwritable(): void
    {
        $blocker = $this->root.'-blocker';
        touch($blocker); // a file where the directory should be
        putenv('PABLO_ANALYTICS_DIR='.$blocker);

        $analytics = new JsonlAnalytics();
        $analytics->taskOpened($this->task()); // must not throw

        // Nothing could be written, and no directory replaced the blocker.
        $this->assertFileExists($blocker);
        $this->assertFalse(is_dir($blocker.'/proj'));
        putenv('PABLO_ANALYTICS_DIR='.$this->root);
    }
}
