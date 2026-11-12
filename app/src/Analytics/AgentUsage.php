<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Usage harvesting for the selected ADE and agent runtime, resolved on every
 * call (never at container compile time): the ADE's own usage first, when it
 * keeps any, else the runtime's storage.
 */
final class AgentUsage implements UsageHarvesterInterface
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly AgentRuntimeRegistry $runtimes = new AgentRuntimeRegistry(),
        private readonly AdeRegistry $ades = new AdeRegistry(),
    ) {
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $ade = $this->ades->resolveOrDefault()->usage($this->runner);
            $runtime = $this->runtimes->resolve()->usage($this->runner);
        } catch (\Throwable) {
            return null;
        }

        return $ade?->harvest($worktree, $launchedAt, $fingerprint)
            ?? $runtime->harvest($worktree, $launchedAt, $fingerprint);
    }
}
