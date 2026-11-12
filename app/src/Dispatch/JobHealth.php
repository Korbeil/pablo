<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * The health of one dispatcher job (a project's sync, poll or main update):
 * when it was last tried, when it last worked, and how it fails. Timestamps
 * are UTC ISO-8601.
 */
final readonly class JobHealth
{
    public function __construct(
        public string $project,
        public string $job,
        public ?string $lastAttemptAt = null,
        public ?string $lastSuccessAt = null,
        public ?string $lastError = null,
        public int $consecutiveFailures = 0,
        public ?string $failingSince = null,
        /** Per-task checks that failed on the last successful poll (poll job only; never a job failure). */
        public int $checkFailures = 0,
    ) {
    }

    public function failing(): bool
    {
        return $this->consecutiveFailures > 0;
    }
}
