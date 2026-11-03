<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeResolver;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Time;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Creates the agent launcher for a backend ('orca' | 'openchamber'), running
 * the selected agent runtime ('opencode' | 'claude-code').
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
        private ?AgentRuntimeResolver $runtimes = null,
    ) {
    }

    /**
     * @throws PabloError for an unknown backend, or OpenChamber with Claude Code
     */
    public function create(?string $backend = null): AgentLauncherInterface
    {
        $backend ??= $this->resolveBackend();
        $runtime = $this->runtime();
        if (null !== ($error = self::incompatibility($backend, $runtime->name()))) {
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

    public function runtimes(): AgentRuntimeResolver
    {
        return $this->runtimes ??= new AgentRuntimeResolver($this->globalConfig);
    }

    /**
     * Why $backend cannot run $runtime, or null when it can. OpenChamber
     * drives its own OpenCode server, so it only ever runs OpenCode.
     */
    public static function incompatibility(string $backend, string $runtime): ?string
    {
        if ('openchamber' === $backend && AgentRuntimeInterface::CLAUDE_CODE === $runtime) {
            return 'OpenChamber only works with OpenCode: agent_runtime claude-code cannot run on agent_backend openchamber. '
                .'Switch to agent_backend: orca, or to agent_runtime: opencode.';
        }

        return null;
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
