<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Symfony\Component\Process\Process;

/**
 * A JobWorkerInterface over a started Symfony Process. The pool enforces the
 * timeout itself (WorkerPool), so the Process has none of its own.
 */
final class ProcessJobWorker implements JobWorkerInterface
{
    /** Seconds stop() leaves the child after SIGTERM before SIGKILL. */
    private const STOP_GRACE_S = 5.0;

    public function __construct(private readonly Process $process)
    {
    }

    public function isRunning(): bool
    {
        return $this->process->isRunning();
    }

    public function output(): string
    {
        return $this->process->getOutput();
    }

    public function errorOutput(): string
    {
        return $this->process->getErrorOutput();
    }

    public function exitCode(): ?int
    {
        return $this->process->isRunning() ? null : $this->process->getExitCode();
    }

    public function stop(): void
    {
        if ($this->process->isRunning()) {
            $this->process->stop(self::STOP_GRACE_S);
        }
    }
}
