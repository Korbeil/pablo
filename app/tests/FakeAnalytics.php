<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Analytics\AgentRunRecord;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Domain\State;
use Pablo\Domain\Task;

/**
 * Capturing test double for AnalyticsInterface — the analytics counterpart
 * of FakeAgents: tests assert on recorded events instead of anything being
 * written under ~/.pablo/analytics.
 */
final class FakeAnalytics implements AnalyticsInterface
{
    /** @var list<Task> */
    public array $opened = [];

    /** @var list<array{task: Task, from: State}> */
    public array $transitions = [];

    /** @var list<array{project: string, branch: string, worktree: string, agent: string, backend: string, runId: string, launchedAt: string, prompt: string}> */
    public array $runsStarted = [];

    /** @var list<AgentRunRecord> */
    public array $runsFinished = [];

    /** @var list<Task> */
    public array $closed = [];

    public function taskOpened(Task $task): void
    {
        $this->opened[] = $task;
    }

    public function stateEntered(Task $task, State $from): void
    {
        $this->transitions[] = ['task' => $task, 'from' => $from];
    }

    public function agentRunStarted(string $project, string $branch, string $worktree, string $agent, string $backend, string $runId, string $launchedAt, string $prompt): void
    {
        $this->runsStarted[] = [
            'project' => $project,
            'branch' => $branch,
            'worktree' => $worktree,
            'agent' => $agent,
            'backend' => $backend,
            'runId' => $runId,
            'launchedAt' => $launchedAt,
            'prompt' => $prompt,
        ];
    }

    public function agentRunFinished(AgentRunRecord $run): void
    {
        $this->runsFinished[] = $run;
    }

    public function taskClosed(Task $task): void
    {
        $this->closed[] = $task;
    }
}
