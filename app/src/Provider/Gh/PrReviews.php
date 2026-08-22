<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * A PR's author plus its reviews, as fetched for review evaluation.
 */
final readonly class PrReviews
{
    /**
     * @param list<ReviewNode> $reviews
     */
    public function __construct(
        public string $author,
        public array $reviews,
    ) {
    }
}
