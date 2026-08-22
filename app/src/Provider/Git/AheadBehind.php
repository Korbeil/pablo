<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

/**
 * Commit counts of a worktree relative to its upstream: how many commits it
 * is behind (needs integrating) and ahead (unpushed).
 */
final readonly class AheadBehind
{
    public function __construct(
        public int $behind,
        public int $ahead,
    ) {
    }
}
