<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Usage harvesting for whichever agent runtime is selected, resolved on
 * every call (never at container compile time).
 */
final class AgentUsage implements UsageHarvesterInterface
{
    public function __construct(
        private readonly AgentRuntimeRegistry $runtimes = new AgentRuntimeRegistry(),
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
    ) {
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $harvester = $this->runtimes->resolve()->usage($this->runner);
        } catch (\Throwable) {
            return null;
        }

        return $harvester->harvest($worktree, $launchedAt, $fingerprint);
    }
}
