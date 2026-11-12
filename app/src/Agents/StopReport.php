<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * What AgentRuns::stop() did to one run: the ADE's outcome and, for a
 * stopped rebase conflict resolver, how its worktree was left clean
 * (null when nothing was in progress).
 */
final readonly class StopReport
{
    public function __construct(
        public string $agent,
        public string $handle,
        public AgentStop $outcome,
        public ?string $cleanup = null,
    ) {
    }
}
