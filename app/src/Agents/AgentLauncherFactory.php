<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Time;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Creates the agent launcher for a backend ('orca' | 'openchamber'), running
 * the selected agent runtime (see AgentRuntimeRegistry).
 *
 * Backend and runtime are resolved per call — env override first, then the
 * global config — never at container compile time (the compiled container is
 * cached under app/var/cache/<env>). Collaborators default to real
 * implementations in the signature so hand-rolled instances stay simple.
 */
final class AgentLauncherFactory
{
    public const BACKENDS = ['orca', 'openchamber'];

    public function __construct(
        private readonly GlobalConfig $globalConfig,
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly Time $time = new Time(),
        private ?AgentRuntimeRegistry $runtimes = null,
    ) {
    }

    /**
     * @throws PabloError for an unknown backend, or one that cannot run the selected runtime
     */
    public function create(?string $backend = null): AgentLauncherInterface
    {
        $backend ??= $this->resolveBackend();
        $runtime = $this->runtime();
        if (null !== ($error = $runtime->backendError($backend))) {
            throw new PabloError($error);
        }

        return match ($backend) {
            'orca' => new Agents($this->runner, $this->time, runtime: $runtime),
            'openchamber' => new OpenChamber($this->runner, $this->time),
            default => throw new PabloError("unknown agent backend '{$backend}' (expected one of: orca, openchamber)"),
        };
    }

    public function runtime(): AgentRuntimeInterface
    {
        return $this->runtimes()->resolve();
    }

    public function runtimes(): AgentRuntimeRegistry
    {
        return $this->runtimes ??= new AgentRuntimeRegistry($this->globalConfig);
    }

    public function resolveBackend(): string
    {
        $env = getenv('PABLO_AGENT_BACKEND');
        if (false !== $env && '' !== $env) {
            return $env;
        }
        $config = $this->globalConfig->defaults()['agent_backend'] ?? null;
        if (\is_string($config) && '' !== $config) {
            return $config;
        }

        return 'orca';
    }
}
