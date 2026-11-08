<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Headless;

use Pablo\Agents\Ade\AdeInterface;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * No ADE: agents run headless in the background, whatever the runtime.
 * Selected explicitly with `agent_backend: headless`; the other ADEs fall
 * back to the same runs (HeadlessRunner) when they cannot launch.
 */
final class HeadlessAde implements AdeInterface
{
    public const NAME = 'headless';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'headless';
    }

    public function launcher(AgentRuntimeInterface $runtime, ProcessRunnerInterface $runner, Time $time): AgentLauncherInterface
    {
        return new HeadlessLauncher($runner, $time, runtime: $runtime);
    }

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

    public function followHint(): string
    {
        return 'it runs headless: follow it with pablo task:list';
    }
}
