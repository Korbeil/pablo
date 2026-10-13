<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Domain\State;

/**
 * Result of attaching an existing worktree as a task, shared by the CLI
 * (task:attach) and any other surface that adopts a foreign worktree.
 */
final class AttachResult
{
    public function __construct(
        public readonly string $project,
        public readonly string $branch,
        public readonly string $worktreePath,
        public readonly State $state,
    ) {
    }
}
