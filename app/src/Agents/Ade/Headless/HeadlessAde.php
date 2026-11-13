<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Headless;

use Pablo\Agents\Ade\AbstractAde;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * No ADE: agents run headless in the background, whatever the runtime.
 * Selected explicitly with `agent_backend: headless`; the other ADEs fall
 * back to the same runs (HeadlessRunner) when they cannot launch.
 */
final class HeadlessAde extends AbstractAde
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
        return new HeadlessLauncher($runner, $runtime, $time);
    }

    public function followHint(): string
    {
        return 'it runs headless: follow it with pablo task:list';
    }
}
