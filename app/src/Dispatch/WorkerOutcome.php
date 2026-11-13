<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * How one pooled job ended: its own output (stdout without the result line,
 * stderr), its JobResult — or the reason there is none — and its wall time.
 */
final readonly class WorkerOutcome
{
    public function __construct(
        public PoolJob $job,
        public string $output,
        public string $errorOutput,
        public ?JobResult $result,
        public float $durationS,
        /** Set when the child left no result: crashed, killed, timed out or never started. */
        public ?string $failure = null,
    ) {
    }

    public function ok(): bool
    {
        return null === $this->failure && null !== $this->result && $this->result->ok();
    }

    /** The one-line error recorded in the job's health. */
    public function error(): string
    {
        return $this->failure ?? $this->result->error ?? 'unknown error';
    }
}
