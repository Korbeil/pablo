<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\OpenChamber;

use Pablo\Agents\Ade\AbstractAde;
use Pablo\Agents\Ade\Headless\HeadlessAde;
use Pablo\Agents\Ade\Orca\OrcaAde;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * OpenChamber: directory-based sessions driven by OpenChamber's own OpenCode
 * server — so it only ever runs the OpenCode runtime (the one ADE allowed to
 * name a concrete runtime, see RuntimeConfinementTest). Directory-based:
 * nothing to back up or register on restore.
 */
final class OpenChamberAde extends AbstractAde
{
    public const NAME = 'openchamber';

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'OpenChamber';
    }

    public function launcher(AgentRuntimeInterface $runtime, ProcessRunnerInterface $runner, Time $time): AgentLauncherInterface
    {
        return new OpenChamberLauncher($runner, $time, runtime: $runtime);
    }

    public function runtimeError(AgentRuntimeInterface $runtime): ?string
    {
        if (OpenCodeRuntime::NAME === $runtime->name()) {
            return null;
        }

        return "OpenChamber only works with OpenCode: agent_runtime {$runtime->name()} cannot run on agent_backend ".self::NAME.'. '
            .'Switch to agent_backend: '.OrcaAde::NAME.' or '.HeadlessAde::NAME.', or to agent_runtime: '.OpenCodeRuntime::NAME.'.';
    }

    public function probe(): CliProbe
    {
        return new CliProbe(
            cli: 'openchamber',
            argv: ['openchamber', 'status'],
            hint: 'install the OpenChamber CLI ('.OpenChamberCli::INSTALL.'), ahead of the desktop app on your PATH: it drives the running desktop app',
            installCommands: [
                'Darwin' => 'curl -fsSL https://raw.githubusercontent.com/openchamber/openchamber/main/scripts/install.sh | bash',
                'Linux' => 'curl -fsSL https://raw.githubusercontent.com/openchamber/openchamber/main/scripts/install.sh | bash',
            ],
            docsUrl: 'https://github.com/openchamber/openchamber',
            // Without OpenChamber, agents fall back to a headless run.
            soft: true,
            refuse: OpenChamberCli::refusal(...),
        );
    }

    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface
    {
        return new OpenChamberUsage($runner);
    }

    public function followHint(): string
    {
        return 'open it in OpenChamber';
    }
}
