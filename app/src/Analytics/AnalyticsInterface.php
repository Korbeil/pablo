<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Domain\State;
use Pablo\Domain\Task;

/**
 * The analytics seam: append-only event recording for task lifetimes and
 * agent runs. Implementations must never throw into the caller's flow —
 * losing an analytics event is always preferable to breaking a task
 * operation (JsonlAnalytics swallows everything; NullAnalytics drops all).
 */
interface AnalyticsInterface
{
    /** Emitted once by task:start when a new task record is created. */
    public function taskOpened(Task $task): void;

    /**
     * Emitted by StateMachine::enterState() after the transition persisted,
     * so consecutive events per task yield dwell times and rework loops.
     */
    public function stateEntered(Task $task, State $from): void;

    /**
     * Emitted inside internal:launch-agent before the backend launches.
     * $prompt is hashed to a short fingerprint here — prompt content itself
     * is never persisted.
     */
    public function agentRunStarted(string $project, string $branch, string $worktree, string $agent, string $backend, string $runId, string $launchedAt, string $prompt): void;

    /** Emitted inside internal:watch-agent once the agent run concluded. */
    public function agentRunFinished(AgentRunRecord $run): void;

    /** Emitted just before a task record is deleted (manual close or merge auto-close). */
    public function taskClosed(Task $task): void;
}
