<?php

declare(strict_types=1);

namespace Pablo\Listing;

/**
 * The PR attached to a Slack export row.
 */
final readonly class SlackPr
{
    public function __construct(
        public int $number,
        public string $title,
        public string $url,
        public bool $isDraft,
    ) {
    }
}
