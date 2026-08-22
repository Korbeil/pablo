<?php

declare(strict_types=1);

namespace Pablo\Listing;

use Pablo\Domain\Issue;

/**
 * One row of the paste-ready Slack export.
 */
final readonly class SlackItem
{
    public function __construct(
        public string $project,
        public string $branch,
        public ?Issue $issue,
        public ?string $summary,
        public ?SlackPr $pr,
    ) {
    }
}
