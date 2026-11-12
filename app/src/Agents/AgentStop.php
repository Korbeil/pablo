<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * Outcome of AgentLauncherInterface::stop(): whether the run is gone (it
 * was stopped, or had already ended) and a line saying what happened — or
 * why the ADE could not stop it.
 */
final readonly class AgentStop
{
    public function __construct(
        public bool $stopped,
        public string $detail,
    ) {
    }
}
