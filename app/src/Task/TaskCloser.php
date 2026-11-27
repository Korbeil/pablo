<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Config\Config;
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
     * The task worktree's uncommitted files (see GitRepoInterface::uncommittedChanges()),
     * the list to show before asking to discard them.
     *
     * @return list<string>
     */
    public function uncommittedChanges(string $project, string $branch): array
    {
        $task = $this->store->get($project, $branch)
            ?? throw new PabloError("no task {$branch} in project '{$project}'");

        return $this->git->uncommittedChanges($task->worktreePath);
    }

    /**
     * The approval token for a list of uncommitted files: what close() takes
     * as $discard, so a caller needs to keep only this, not the whole list.
     *
     * @param list<string> $files
     */
    public static function fingerprint(array $files): string
    {
        sort($files);

        return hash('sha256', implode("\0", $files));
    }

    /**
     * @param bool        $force   close even while agent sessions are active
     * @param string|null $discard the fingerprint() of the uncommitted files the user approved
     *                             losing; null approves none. A worktree with files is only
     *                             removed when this matches its current list, so a file that
     *                             appeared since the approval is never deleted unseen
     *
     * @return string what happened to the worktree ("worktree removed", "stale worktree pruned",
     *                or "broken worktree pruned, …" when git could no longer remove its directory)
     *
     * @throws UncommittedChanges when the worktree has files that were not approved
     */
    public function close(string $project, string $branch, bool $force = false, ?string $discard = null): string
    {
        $task = $this->store->get($project, $branch)
            ?? throw new PabloError("no task {$branch} in project '{$project}'");
        $cfg = $this->projectsLoader->loadProjects()[$project]
            ?? throw $this->projectsLoader->missingTaskProject($project, $branch);
        $sessions = $this->agents->activeSessions($task->worktreePath);
        if ([] !== $sessions && !$force) {
            throw new PabloError(\count($sessions).' agent session(s) still active on this worktree — wait for them or force the close');
        }

        $lock = $this->store->taskLock($project, $branch);
        try {
            $changes = $this->git->uncommittedChanges($task->worktreePath);
            if ([] !== $changes) {
                if (null === $discard) {
                    throw new UncommittedChanges(\count($changes).' uncommitted file(s) in the worktree — commit or stash them, or approve deleting them', $changes);
                }
                if (!hash_equals(self::fingerprint($changes), $discard)) {
                    throw new UncommittedChanges('the uncommitted files changed since you approved deleting them — review the new list', $changes);
                }
            }
            $hadWorktree = is_dir($task->worktreePath);
            $this->git->removeWorktree($cfg->repo->path, $task->worktreePath, $task->branch, discardChanges: [] !== $changes);
            // Before the record disappears: the close event is what preserves
            // the task's final metadata for analytics.
            $this->analytics->taskClosed($task);
            $this->store->delete($project, $branch);
        } finally {
            $lock->release();
        }

        if (!$hadWorktree) {
            return 'stale worktree pruned';
        }

        return is_dir($task->worktreePath)
            ? "broken worktree pruned, its directory was left in place: {$task->worktreePath}"
            : 'worktree removed';
    }
}
