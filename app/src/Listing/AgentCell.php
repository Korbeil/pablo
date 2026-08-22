<?php

declare(strict_types=1);

namespace Pablo\Listing;

/**
 * The two agent columns of a task row: session count and activity summary.
 */
final readonly class AgentCell
{
    public function __construct(
        public string $count,
        public string $activity,
    ) {
    }
}
