<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

use Pablo\Agents\Ade\Headless\HeadlessAde;
use Pablo\Agents\Ade\OpenChamber\OpenChamberAde;
use Pablo\Agents\Ade\Orca\OrcaAde;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;

/**
 * Every ADE PABLO knows — the one place that lists them — and the selected
 * one: PABLO_AGENT_BACKEND, then `agent_backend` in ~/.pablo/config.yaml,
 * then the default (Orca). Resolved on every call, never at container
 * compile time (the compiled container is cached).
 *
 * Adding an ADE: implement AdeInterface (and an AbstractAgentLauncher) in
 * its own folder, then list it in NAMES and build().
 */
final class AdeRegistry
{
    /**
     * In detection order: system:install picks the first one found when the
     * default is missing (headless, needing no CLI, is never picked that way:
     * the default stays, so the ADE is used as soon as it is installed).
     */
    public const NAMES = [OrcaAde::NAME, OpenChamberAde::NAME, HeadlessAde::NAME];

    public const DEFAULT = OrcaAde::NAME;

    /** @var array<string, AdeInterface> */
    private array $ades = [];

    public function __construct(private readonly GlobalConfig $globalConfig = new GlobalConfig())
    {
    }

    /**
     * The configured name, unchecked (see checkName()).
     */
    public function resolveName(): string
    {
        $env = getenv('PABLO_AGENT_BACKEND');
        if (false !== $env && '' !== $env) {
            return $env;
        }
        $config = $this->globalConfig->defaults()['agent_backend'] ?? null;

        return \is_string($config) && '' !== $config ? $config : self::DEFAULT;
    }

    /**
     * @throws PabloError unless $name is a known ADE
     */
    public static function checkName(string $name, string $what = 'agent_backend'): string
    {
        if (!\in_array($name, self::NAMES, true)) {
            throw new PabloError(\sprintf("unknown %s '%s' (expected one of: %s)", $what, $name, implode(', ', self::NAMES)));
        }

        return $name;
    }

    /**
     * The ADE named $name, or the selected one.
     *
     * @throws PabloError on an unknown ADE name
     */
    public function resolve(?string $name = null): AdeInterface
    {
        $name = self::checkName($name ?? $this->resolveName());

        return $this->ades[$name] ??= $this->build($name);
    }

    /**
     * The selected ADE, or the default one when the configured name is
     * unknown (for checks that report the bad name themselves).
     */
    public function resolveOrDefault(): AdeInterface
    {
        try {
            return $this->resolve();
        } catch (PabloError) {
            return $this->resolve(self::DEFAULT);
        }
    }

    /** @return list<AdeInterface> every ADE, in NAMES order */
    public function all(): array
    {
        return array_map($this->resolve(...), self::NAMES);
    }

    private function build(string $name): AdeInterface
    {
        return match ($name) {
            OpenChamberAde::NAME => new OpenChamberAde(),
            HeadlessAde::NAME => new HeadlessAde(),
            default => new OrcaAde(),
        };
    }
}
