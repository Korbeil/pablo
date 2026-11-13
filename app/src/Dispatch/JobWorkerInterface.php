<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * One dispatcher job running in a child process (`pablo internal:dispatch-job`),
 * as started by a JobWorkerSpawnerInterface. Its output is buffered whole, so
 * the dispatcher can print it in one piece once the child is done.
 */
interface JobWorkerInterface
{
    public function isRunning(): bool;

    /** Everything the child wrote on stdout so far (all of it once it has exited). */
    public function output(): string;

    /** Everything the child wrote on stderr so far. */
    public function errorOutput(): string;

    /** The child's exit code, null while it runs (or when it was killed by a signal). */
    public function exitCode(): ?int;

    /** Kill the child (a hung or timed-out job); a no-op once it has exited. */
    public function stop(): void;
}
