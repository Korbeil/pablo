<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

use Pablo\Agents\Ade\Headless\HeadlessAde;
use Pablo\Agents\Ade\OpenChamber\OpenChamberAde;
use Pablo\Agents\Ade\Orca\OrcaAde;
use Pablo\Agents\NamedRegistry;

/**
 * Every ADE PABLO knows — the one place that lists them — and the selected
 * one: PABLO_AGENT_BACKEND, then `agent_backend` in ~/.pablo/config.yaml,
 * then the default (Orca). Resolved on every call, never at container
 * compile time (the compiled container is cached).
 *
 * Adding an ADE: implement AdeInterface (and an AbstractAgentLauncher) in
 * its own folder, then list it in NAMES and build().
 *
 * @extends NamedRegistry<AdeInterface>
 */
final class AdeRegistry extends NamedRegistry
{
    public const string ENV = 'PABLO_AGENT_BACKEND';

    public const string CONFIG_KEY = 'agent_backend';

    /**
     * In detection order: system:install picks the first one found when the
     * default is missing (headless, needing no CLI, is never picked that way:
     * the default stays, so the ADE is used as soon as it is installed).
     */
    public const array NAMES = [OrcaAde::NAME, OpenChamberAde::NAME, HeadlessAde::NAME];

    public const string DEFAULT = OrcaAde::NAME;

    protected function build(string $name): AdeInterface
    {
        return match ($name) {
            OpenChamberAde::NAME => new OpenChamberAde(),
            HeadlessAde::NAME => new HeadlessAde(),
            default => new OrcaAde(),
        };
    }
}
