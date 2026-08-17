<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;

/**
 * Selects the agent backend at runtime.
 *
 * Resolution order (never at container compile time — the compiled container
 * is cached, so a compile-time value would freeze on whichever process warmed
 * the cache):
 *   1. an explicit $backend argument (threaded through the internal
 *      subprocesses via --backend);
 *   2. the PABLO_AGENT_BACKEND env var;
 *   3. the `agent_backend` key in ~/.pablo/config.yaml;
 *   4. default `orca`.
 */
final class AgentLauncher
{
    public const BACKENDS = ['orca', 'openchamber'];

    public static function create(?string $backend = null): AgentLauncherInterface
    {
        $backend ??= self::resolveBackend();

        return match ($backend) {
            'orca' => new Agents(),
            'openchamber' => new OpenChamber(),
            default => throw new PabloError("unknown agent backend '{$backend}' (expected one of: orca, openchamber)"),
        };
    }

    public static function resolveBackend(): string
    {
        $env = getenv('PABLO_AGENT_BACKEND');
        if (false !== $env && '' !== $env) {
            return $env;
        }
        $config = GlobalConfig::defaults()['agent_backend'] ?? null;
        if (\is_string($config) && '' !== $config) {
            return $config;
        }

        return 'orca';
    }
}
