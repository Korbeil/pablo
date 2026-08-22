<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * One per-job run stamp: when the job last ran and how long it took.
 */
final readonly class RunStamp
{
    public function __construct(
        public float $ranAt,
        public ?float $durationS,
    ) {
    }
}
