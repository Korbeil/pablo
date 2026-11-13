<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Support\ProcessRunnerInterface;

/**
 * The neutral answers of an ADE that adds nothing of its own: it runs every
 * runtime, needs no CLI, takes the launch context, keeps no usage and has
 * nothing to back up or restore. Each ADE overrides only what differs.
 */
abstract class AbstractAde implements AdeInterface
{
    public function runtimeError(AgentRuntimeInterface $runtime): ?string
    {
        return null;
    }

    public function probe(): ?CliProbe
    {
        return null;
    }

    public function takesLaunchContext(): bool
    {
        return true;
    }

    public function usage(ProcessRunnerInterface $runner): ?UsageHarvesterInterface
    {
        return null;
    }

    public function backup(ProcessRunnerInterface $runner): array
    {
        return [];
    }

    public function restore(ProcessRunnerInterface $runner, array $repoPaths): array
    {
        return [];
    }
}
