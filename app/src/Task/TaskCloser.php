<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;

/**
 * The core of `pablo task:close`, shared by the CLI command and the
 * dashboard's "Close the task" dialog so neither surface can drift.
 *
 * Removes the worktree, the local branch and the task record; the pull
 * request itself is never touched. Like TaskStateChanger, a deliberate
 * dashboard write exception: it only runs on an explicit confirm.
 */
final class TaskCloser
{
    public function __construct(
        private readonly GitRepoInterface $git,
        private readonly Store $store,
        private readonly Config $projectsLoader,
        private readonly AgentLauncherInterface $agents,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
    }

    /**
     * @param bool       $force    close even while agent sessions are active
     * @param State|null $onlyFrom refuse unless the task is in this state (the dashboard passes closed)
     *
     * @return string what happened to the worktree ("worktree removed" or "stale worktree pruned")
     */
    public function close(string $project, string $branch, bool $force = false, ?State $onlyFrom = null): string
    {
        $task = $this->store->get($project, $branch)
            ?? throw new PabloError("no task {$branch} in project '{$project}'");
        $cfg = $this->projectsLoader->loadProjects()[$project]
            ?? throw $this->projectsLoader->missingTaskProject($project, $branch);
        if (null !== $onlyFrom && $onlyFrom !== $task->state) {
            throw new PabloError("task is {$task->state->value}, not {$onlyFrom->value} — nothing to close here");
        }
        $sessions = $this->agents->activeSessions($task->worktreePath);
        if ([] !== $sessions && !$force) {
            throw new PabloError(\count($sessions).' agent session(s) still active on this worktree — wait for them or force the close');
        }

        $lock = $this->store->taskLock($project, $branch);
        try {
            $hadWorktree = is_dir($task->worktreePath);
            $this->git->removeWorktree($cfg->repoPath, $task->worktreePath, $task->branch);
            // Before the record disappears: the close event is what preserves
            // the task's final metadata for analytics.
            $this->analytics->taskClosed($task);
            $this->store->delete($project, $branch);
        } finally {
            $lock->release();
        }

        return $hadWorktree ? 'worktree removed' : 'stale worktree pruned';
    }
}
