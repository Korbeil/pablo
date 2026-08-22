<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

/**
 * One git worktree: its path and checked-out branch.
 */
final readonly class WorktreeRef
{
    public function __construct(
        public string $path,
        public string $branch,
    ) {
    }
}
