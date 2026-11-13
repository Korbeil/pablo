<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * One job queued in the WorkerPool. Two jobs with the same $exclusiveKey (the
 * project's repository path) never run at the same time: projects sharing a
 * repository would otherwise run git (worktree remove, branch -D) on it at once.
 */
final readonly class PoolJob
{
    public function __construct(
        public string $project,
        public string $job,
        public string $exclusiveKey,
    ) {
    }
}
