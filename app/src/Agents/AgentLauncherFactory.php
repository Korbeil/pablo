<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Domain\Time;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Creates the agent launcher of the selected ADE (see AdeRegistry), running
 * the selected agent runtime (see AgentRuntimeRegistry). Code that needs an
 * ADE or a runtime itself injects the registry, not this factory.
 *
 * ADE and runtime are resolved per call — env override first, then the
 * global config — never at container compile time (the compiled container is
 * cached under app/var/cache/<env>). The process runner is required (never
 * defaulted to a real one) so a hand-rolled instance cannot reach real CLIs
 * by omission.
 */
final class AgentLauncherFactory
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly AgentRuntimeRegistry $runtimes,
        private readonly AdeRegistry $ades,
        private readonly Time $time = new Time(),
    ) {
    }

    /**
     * @throws PabloError for an unknown ADE, or one that cannot run the selected runtime
     */
    public function create(?string $ade = null): AgentLauncherInterface
    {
        $ade = $this->ades->resolve($ade);
        $runtime = $this->runtimes->resolve();
        if (null !== ($error = $ade->runtimeError($runtime))) {
            throw new PabloError($error);
        }

        return $ade->launcher($runtime, $this->runner, $this->time);
    }
}
