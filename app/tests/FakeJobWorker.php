<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Dispatch\JobWorkerInterface;

/** One fake child of FakeJobWorkers, following its FakeJobWorkerScript. */
final class FakeJobWorker implements JobWorkerInterface
{
    private bool $running = true;
    private int $checks = 0;

    public function __construct(
        private readonly FakeJobWorkers $owner,
        private readonly string $id,
        private readonly FakeJobWorkerScript $script,
    ) {
    }

    public function isRunning(): bool
    {
        if (!$this->running) {
            return false;
        }
        ++$this->checks;
        if (null !== $this->script->ticks && $this->checks >= $this->script->ticks) {
            $this->running = false;
            $this->owner->ended($this->id, false);
        }

        return $this->running;
    }

    public function output(): string
    {
        return $this->running ? '' : $this->script->stdout;
    }

    public function errorOutput(): string
    {
        return $this->running ? '' : $this->script->stderr;
    }

    public function exitCode(): ?int
    {
        return $this->running ? null : $this->script->exitCode;
    }

    public function stop(): void
    {
        if ($this->running) {
            $this->running = false;
            // Killed before printing anything (its output is lost with it).
            $this->script->stdout = '';
            $this->script->exitCode = null;
            $this->owner->ended($this->id, true);
        }
    }
}
