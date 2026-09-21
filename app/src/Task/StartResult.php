<?php

declare(strict_types=1);

namespace Pablo\Task;

/**
 * Result of starting a task, shared by the CLI (task:start) and the
 * dashboard's new-task modal so both surfaces render the same outcome.
 */
final class StartResult
{
    public function __construct(
        public readonly string $project,
        public readonly string $branch,
        public readonly string $worktreePath,
        public readonly ?string $issueKey = null,
        public readonly ?string $issueTitle = null,
        public readonly bool $reused = false,
        public readonly ?string $reusedState = null,
    ) {
    }
}
