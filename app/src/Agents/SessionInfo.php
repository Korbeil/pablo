<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * Session info for an agent attached to a worktree.
 */
final class SessionInfo
{
    public function __construct(
        public string $handle,
        public string $status, // "running" | "waiting"
    ) {
    }
}
