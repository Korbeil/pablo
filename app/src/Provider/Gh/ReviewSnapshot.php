<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * Everything GhPrInterface::reviewVerdict() reads of one PR: its author,
 * every review and thread, and GitHub's last "ready for review" anchor.
 */
final readonly class ReviewSnapshot
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
        return ReviewVerdict::evaluate($this->reviews->reviews, $this->reviews->threads, $this->readyAnchor, $this->reviews->author, $botWhitelist);
    }
}
