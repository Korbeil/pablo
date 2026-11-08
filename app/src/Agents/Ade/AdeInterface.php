<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;

/**
 * An agent development environment (ADE): *where* PABLO's agents run and
 * where the user opens them — Orca, OpenChamber, or none (headless). The
 * agent *runtime* (AgentRuntimeInterface) decides what runs there.
 *
 * Everything that differs between ADEs lives behind this interface — code
 * outside Agents\Ade never names a concrete ADE. Selected globally
 * (`agent_backend` in ~/.pablo/config.yaml, overridable by
 * PABLO_AGENT_BACKEND) and resolved per call by AdeRegistry — never at
 * container compile time.
 */
interface AdeInterface
{
    /** The `agent_backend` value, e.g. 'orca'. */
    public function name(): string;

    /** Human name, e.g. 'OpenChamber'. */
    public function label(): string;

    /** The launcher running $runtime's agents in this ADE. */
    public function launcher(AgentRuntimeInterface $runtime, ProcessRunnerInterface $runner, Time $time): AgentLauncherInterface;

    /** Why this ADE cannot run $runtime, or null when it can. */
    public function runtimeError(AgentRuntimeInterface $runtime): ?string;

    /** The CLI system:doctor checks, or null when the ADE needs none. */
    public function probe(): ?CliProbe;

    /**
     * Whether the launch prompt may carry the pre-fetched LaunchContext (an
     * ADE that passes the prompt through a terminal command line cannot).
     */
    public function takesLaunchContext(): bool;

    /**
     * Token/cost usage the ADE itself keeps for a run (tried before the
     * runtime's own storage), or null when it keeps none.
     */
    public function usage(ProcessRunnerInterface $runner): ?UsageHarvesterInterface;

    /**
     * Files system:backup adds for this ADE (name => content); best-effort.
     *
     * @return array<string, string>
     */
    public function backup(ProcessRunnerInterface $runner): array;

    /**
     * Re-register the restored repositories with this ADE (system:restore).
     *
     * @param list<string> $repoPaths
     *
     * @return list<string> one line per repository
     */
    public function restore(ProcessRunnerInterface $runner, array $repoPaths): array;

    /** Where the user finds a freshly (re)launched agent, e.g. 'check Orca for the new terminal tab'. */
    public function followHint(): string;
}
