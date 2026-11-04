<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\PabloError;

/**
 * The core of `pablo task:state` and `pablo task:skip-ci`, shared by the CLI
 * commands and the dashboard's task-actions dialog so neither surface can
 * drift from the other.
 *
 * Like TaskStarter, this is a deliberate dashboard write exception: it only
 * ever runs on an explicit confirm, never on page load or poll.
 *
 * The task is re-read *under* its lock, so a concurrent poller transition is
 * never overwritten with a stale record.
 */
final class TaskStateChanger
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly Store $store,
        private readonly Config $projectsLoader,
        private readonly AgentLauncherInterface $agents,
    ) {
    }

    /** Force a task to $state; $trigger=false only records it (except for waiting). */
    public function forceState(string $project, string $branch, State $state, bool $trigger = true): Task
    {
        return $this->locked($project, $branch, function (TaskCtx $ctx) use ($state, $trigger): void {
            $this->stateMachine->enterState($ctx, $state, trigger: $trigger);
        });
    }

    /** Ignore failing CI checks and move a ci-red task on to ready-to-review. */
    public function skipCi(string $project, string $branch): Task
    {
        return $this->locked($project, $branch, function (TaskCtx $ctx): void {
            if (State::CiRed !== $ctx->task->state) {
                throw new PabloError("task is {$ctx->task->state->value}, not ci-red — nothing to skip");
            }
            $ctx->task->ciIgnored = true;
            $this->stateMachine->enterState($ctx, State::ReadyToReview);
        });
    }

    /** @param callable(TaskCtx): void $fn */
    private function locked(string $project, string $branch, callable $fn): Task
    {
        $lock = $this->store->taskLock($project, $branch);
        try {
            $task = $this->store->get($project, $branch)
                ?? throw new PabloError("no task {$branch} in project '{$project}'");
            $cfg = $this->projectsLoader->loadProjects()[$project]
                ?? throw new PabloError("task {$branch} belongs to project '{$project}', which has no config under projects/ anymore");
            $fn(new TaskCtx(task: $task, cfg: $cfg, store: $this->store, agents: $this->agents));

            return $task;
        } finally {
            $lock->release();
        }
    }
}
