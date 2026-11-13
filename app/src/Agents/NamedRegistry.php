<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;

/**
 * A registry of named implementations selected globally: the ENV variable,
 * then CONFIG_KEY in ~/.pablo/config.yaml, then DEFAULT. Resolved on every
 * call, never at container compile time (the compiled container is cached).
 * Each subclass is the one place listing its implementations: NAMES and
 * build().
 *
 * @template T of object
 */
abstract class NamedRegistry
{
    /** The env override, e.g. PABLO_AGENT_RUNTIME. */
    public const string ENV = '';

    /** The ~/.pablo/config.yaml key, e.g. agent_runtime. */
    public const string CONFIG_KEY = '';

    /** @var list<string> every known name, in detection order */
    public const array NAMES = [];

    public const string DEFAULT = '';

    /** @var array<string, T> */
    private array $built = [];

    public function __construct(protected readonly GlobalConfig $globalConfig = new GlobalConfig())
    {
    }

    /**
     * The configured name (env override, else the given global defaults),
     * unchecked, or null when none is set.
     *
     * @param array<string, mixed> $defaults GlobalConfig::defaults()
     */
    protected static function configuredName(array $defaults): ?string
    {
        $env = getenv(static::ENV);
        $name = (false !== $env && '' !== $env) ? $env : ($defaults[static::CONFIG_KEY] ?? null);

        return \is_string($name) && '' !== $name ? $name : null;
    }

    /**
     * The configured name, unchecked (see checkName()).
     */
    public function resolveName(): string
    {
        return static::configuredName($this->globalConfig->defaults()) ?? static::DEFAULT;
    }

    /**
     * @param string|null $what what the name was given as (default: CONFIG_KEY)
     *
     * @throws PabloError unless $name is a known name
     */
    public static function checkName(string $name, ?string $what = null): string
    {
        if (!\in_array($name, static::NAMES, true)) {
            throw new PabloError(\sprintf("unknown %s '%s' (expected one of: %s)", $what ?? static::CONFIG_KEY, $name, implode(', ', static::NAMES)));
        }

        return $name;
    }

    /**
     * The implementation named $name, or the selected one.
     *
     * @return T
     *
     * @throws PabloError on an unknown name
     */
    public function resolve(?string $name = null): object
    {
        $name = static::checkName($name ?? $this->resolveName());

        return $this->built[$name] ??= $this->build($name);
    }

    /**
     * The selected implementation, or the default one when the configured
     * name is unknown (for checks that report the bad name themselves).
     *
     * @return T
     */
    public function resolveOrDefault(): object
    {
        try {
            return $this->resolve();
        } catch (PabloError) {
            return $this->resolve(static::DEFAULT);
        }
    }

    /** @return list<T> every implementation, in NAMES order */
    public function all(): array
    {
        return array_map($this->resolve(...), static::NAMES);
    }

    /** @return T */
    abstract protected function build(string $name): object;
}
