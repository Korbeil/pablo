<?php

declare(strict_types=1);

namespace Pablo\Analytics;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeResolver;
use Pablo\Support\ProcessRunner;

/**
 * Usage harvesting for whichever agent runtime is selected, resolved on
 * every call (never at container compile time).
 */
final class AgentUsage implements UsageHarvesterInterface
{
    public function __construct(
        private readonly AgentRuntimeResolver $runtimes = new AgentRuntimeResolver(),
        private readonly OpenCodeUsage $openCode = new OpenCodeUsage(new ProcessRunner()),
        private readonly ClaudeCodeUsage $claudeCode = new ClaudeCodeUsage(),
    ) {
    }

    public function harvest(string $worktree, string $launchedAt, ?string $fingerprint): ?UsageSnapshot
    {
        try {
            $runtime = $this->runtimes->resolveName();
        } catch (\Throwable) {
            return null;
        }

        return AgentRuntimeInterface::CLAUDE_CODE === $runtime
            ? $this->claudeCode->harvest($worktree, $launchedAt, $fingerprint)
            : $this->openCode->harvest($worktree, $launchedAt, $fingerprint);
    }
}
