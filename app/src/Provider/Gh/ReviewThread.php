<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * One PR review thread from the GraphQL query.
 *
 * Deliberately timestamp-free: thread resolution is a current state, not an
 * event — a thread resolved by anyone clears the signal (the reviewer's
 * satisfaction matters, not who clicked "Resolve conversation").
 * `isOutdated` is ignored on purpose: an unresolved-but-outdated thread
 * keeps counting as open.
 */
final readonly class ReviewThread
{
    /**
     * @param list<string> $reviewIds GitHub node ids of the reviews whose
     *                                comments live under this thread
     */
    public function __construct(
        public bool $resolved,
        public array $reviewIds,
    ) {
    }

    public function involves(string $reviewId): bool
    {
        return \in_array($reviewId, $this->reviewIds, true);
    }

    /**
     * @param array<string, mixed> $node raw GraphQL node
     */
    public static function fromNode(array $node): self
    {
        $comments = $node['comments']['nodes'] ?? [];
        $reviewIds = [];
        foreach ($comments as $comment) {
            $reviewId = $comment['pullRequestReview']['id'] ?? null;
            if (null !== $reviewId && !\in_array($reviewId, $reviewIds, true)) {
                $reviewIds[] = (string) $reviewId;
            }
        }

        return new self((bool) ($node['isResolved'] ?? false), $reviewIds);
    }
}
