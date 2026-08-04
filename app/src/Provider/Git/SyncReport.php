<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

final class SyncReport
{
    /** @param array<int, string> $conflictFiles */
    public function __construct(
        public string $worktree,
        public string $branch,
        public string $action, // up-to-date | would-sync | synced | conflict | lease-failed | dirty
        public int $behind = 0,
        public int $ahead = 0,
        public array $conflictFiles = [],
        public string $detail = '',
        public string $agentHandle = '', // opencode session ID when conflict agent launched
    ) {
    }
}
