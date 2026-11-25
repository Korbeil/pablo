<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Github;

use Pablo\Provider\Forge\ReviewSnapshot;
use Pablo\Provider\Forge\ReviewVerdict;

/**
 * Everything ForgeInterface::reviewVerdict() reads of one PR: its author,
 * every review and thread, and GitHub's last "ready for review" anchor.
 */
final readonly class GithubReviewSnapshot implements ReviewSnapshot
{
    public function __construct(
        public PrReviews $reviews,
        public \DateTimeImmutable $readyAnchor,
    ) {
    }

    /**
     * @param list<string> $botWhitelist
     *
     * @return ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null
     */
    public function verdict(array $botWhitelist): ?string
    {
        return GithubReviewVerdict::evaluate($this->reviews->reviews, $this->reviews->threads, $this->readyAnchor, $this->reviews->author, $botWhitelist);
    }
}
