<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Domain\State;
use Pablo\Domain\Task;

/**
 * No-op implementation: the default for hand-rolled instances and tests, so
 * nothing ever writes outside a DI-wired process.
 */
final class NullAnalytics implements AnalyticsInterface
{
    public function taskOpened(Task $task): void
    {
    }

    public function stateEntered(Task $task, State $from): void
    {
    }

    public function agentRunStarted(string $project, string $branch, string $worktree, string $agent, string $backend, string $runId, string $launchedAt, string $prompt): void
    {
    }

    public function agentRunFinished(AgentRunRecord $run): void
    {
    }

    public function taskClosed(Task $task): void
    {
    }
}
