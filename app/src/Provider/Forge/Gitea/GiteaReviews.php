<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitea;

use Pablo\Provider\Forge\ReviewSnapshot;

/**
 * Everything GiteaForge::reviewVerdict() reads of one pull request: its
 * author and creation, its title changes (Gitea's draft is a "WIP:" title
 * prefix, so "ready for review" is the title change dropping it), its
 * reviews, and which reviews still have an unresolved comment.
 */
final readonly class GiteaReviews implements ReviewSnapshot
{
    /**
     * @param list<array{at: \DateTimeImmutable, old: string, new: string}>                                                      $titleChanges
     * @param list<array{id: int, login: string, bot: bool, state: string, body: string, submittedAt: ?string, dismissed: bool}> $reviews      state: APPROVED | REQUEST_CHANGES | COMMENT | PENDING | REQUEST_REVIEW
     * @param array<int, true>                                                                                                   $unresolved   review id => it has an unresolved comment
     */
    public function __construct(
        public string $author,
        public \DateTimeImmutable $createdAt,
        public array $titleChanges,
        public array $reviews,
        public array $unresolved,
    ) {
    }

    public function verdict(array $botWhitelist): ?string
    {
        return GiteaReviewVerdict::evaluate($this, $botWhitelist);
    }

    /**
     * When the pull request was last marked ready for review: the last title
     * change dropping the draft prefix, else its creation.
     */
    public function readyAt(): \DateTimeImmutable
    {
        $anchor = $this->createdAt;
        foreach ($this->titleChanges as $change) {
            if (GiteaForge::isDraftTitle($change['old']) && !GiteaForge::isDraftTitle($change['new']) && $change['at'] > $anchor) {
                $anchor = $change['at'];
            }
        }

        return $anchor;
    }

    /**
     * From the REST pull request, its reviews and its issue timeline; null
     * when the pull request lacks its author or creation date.
     *
     * @param array<mixed>       $pr
     * @param list<array<mixed>> $reviews
     * @param list<array<mixed>> $timeline
     * @param array<int, true>   $unresolved
     */
    public static function fromApi(array $pr, array $reviews, array $timeline, array $unresolved): ?self
    {
        $author = $pr['user']['login'] ?? null;
        $createdAt = $pr['created_at'] ?? null;
        if (!\is_string($author) || !\is_string($createdAt)) {
            return null;
        }
        $titleChanges = [];
        foreach ($timeline as $event) {
            if ('change_title' === ($event['type'] ?? null) && \is_string($event['created_at'] ?? null)) {
                $titleChanges[] = [
                    'at' => new \DateTimeImmutable($event['created_at']),
                    'old' => (string) ($event['old_title'] ?? ''),
                    'new' => (string) ($event['new_title'] ?? ''),
                ];
            }
        }
        $list = [];
        foreach ($reviews as $review) {
            $list[] = [
                'id' => (int) ($review['id'] ?? 0),
                'login' => (string) ($review['user']['login'] ?? ''),
                'bot' => self::isBot($review['user'] ?? null),
                'state' => strtoupper((string) ($review['state'] ?? '')),
                'body' => trim((string) ($review['body'] ?? '')),
                'submittedAt' => \is_string($review['submitted_at'] ?? null) ? $review['submitted_at'] : null,
                'dismissed' => true === ($review['dismissed'] ?? false),
            ];
        }

        return new self($author, new \DateTimeImmutable($createdAt), $titleChanges, $list, $unresolved);
    }

    /**
     * The review ids whose comments decide whether they still count: a
     * submitted comment review that has comments.
     *
     * @param list<array<mixed>> $reviews REST reviews
     *
     * @return list<int>
     */
    public static function threadedCommentReviews(array $reviews): array
    {
        $ids = [];
        foreach ($reviews as $review) {
            if ('COMMENT' === strtoupper((string) ($review['state'] ?? '')) && (int) ($review['comments_count'] ?? 0) > 0 && isset($review['id'])) {
                $ids[] = (int) $review['id'];
            }
        }

        return $ids;
    }

    /** @param list<array<mixed>> $comments a review's REST comments */
    public static function hasUnresolved(array $comments): bool
    {
        foreach ($comments as $comment) {
            if (!\is_array($comment['resolver'] ?? null)) {
                return true;
            }
        }

        return false;
    }

    private static function isBot(mixed $user): bool
    {
        if (!\is_array($user)) {
            return false;
        }

        return 'bot' === strtolower((string) ($user['type'] ?? '')) || str_ends_with((string) ($user['login'] ?? ''), '[bot]');
    }
}
