<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * The last dispatcher run: when it started and finished (UTC ISO-8601), and
 * how it ended — one of DispatchHealth::OUTCOME_*.
 */
final readonly class DispatchRecord
{
    public function __construct(
        public string $startedAt,
        public ?string $finishedAt,
        public string $outcome,
        public ?string $detail = null,
    ) {
    }
}
