<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Agents\Ade\AdeInterface;
use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Time;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Creates the agent launcher of the selected ADE (see AdeRegistry), running
 * the selected agent runtime (see AgentRuntimeRegistry).
 *
 * ADE and runtime are resolved per call — env override first, then the
 * global config — never at container compile time (the compiled container is
 * cached under app/var/cache/<env>). Collaborators default to real
 * implementations in the signature so hand-rolled instances stay simple.
 */
final class AgentLauncherFactory
{
    public function __construct(
        private readonly GlobalConfig $globalConfig,
        private readonly ProcessRunnerInterface $runner = new ProcessRunner(),
        private readonly Time $time = new Time(),
        private ?AgentRuntimeRegistry $runtimes = null,
        private ?AdeRegistry $ades = null,
    ) {
    }

    /**
     * @throws PabloError for an unknown ADE, or one that cannot run the selected runtime
     */
    public function create(?string $ade = null): AgentLauncherInterface
    {
        $ade = $this->ades()->resolve($ade);
        $runtime = $this->runtime();
        if (null !== ($error = $ade->runtimeError($runtime))) {
            throw new PabloError($error);
        }

        return $ade->launcher($runtime, $this->runner, $this->time);
    }

    public function runtime(): AgentRuntimeInterface
    {
        return $this->runtimes()->resolve();
    }

    public function runtimes(): AgentRuntimeRegistry
    {
        return $this->runtimes ??= new AgentRuntimeRegistry($this->globalConfig);
    }

    /**
     * @throws PabloError on an unknown agent_backend
     */
    public function ade(): AdeInterface
    {
        return $this->ades()->resolve();
    }

    public function ades(): AdeRegistry
    {
        return $this->ades ??= new AdeRegistry($this->globalConfig);
    }

    /** The configured agent_backend, unchecked. */
    public function resolveBackend(): string
    {
        return $this->ades()->resolveName();
    }
}
