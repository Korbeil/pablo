<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitlab;

use Pablo\Provider\Forge\ReviewVerdict;

/**
 * The pure review evaluation behind GitlabForge::reviewVerdict(), GitLab's
 * take on site/content/states.html#reviews:
 *
 *  - only reviewers count: never the merge request's author, never a bot
 *    unless review.bot_whitelist names it;
 *  - the anchor is the last "marked this merge request as ready" system
 *    note (else the creation): an older signal belongs to a previous round;
 *  - changes: a reviewer whose review state is "requested changes" (an
 *    explicit verdict, whatever the anchor: GitLab clears it once the
 *    reviewer reviews again), or an unresolved thread holding a reviewer's
 *    comment posted after the anchor — resolving the thread clears it;
 *  - approved: a current approval (GitLab drops approvals itself, on push
 *    when the project says so) given after the anchor;
 *  - changes beat approvals.
 */
final class GitlabReviewVerdict
{
    /** System note bodies marking the merge request ready, old and new wording. */
    private const READY_NOTE = '/^(marked this merge request as \*\*ready\*\*|marked as \*\*ready\*\*|unmarked (this merge request )?as a \*\*(work in progress|draft)\*\*)/i';

    private const APPROVED_NOTE = '/^approved this merge request/i';

    /**
     * @param list<string> $botWhitelist
     *
     * @return ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null
     */
    public static function evaluate(GitlabReviews $mr, array $botWhitelist): ?string
    {
        $counts = static fn (string $username, bool $bot): bool => '' !== $username
            && $username !== $mr->author
            && (!$bot || \in_array($username, $botWhitelist, true));

        $anchor = $mr->createdAt;
        /** @var array<string, \DateTimeImmutable> $approvedAt username => its latest approval note */
        $approvedAt = [];
        foreach ($mr->discussions as $discussion) {
            foreach ($discussion->notes as $note) {
                if (!$note['system']) {
                    continue;
                }
                $body = trim($note['body']);
                if (1 === preg_match(self::READY_NOTE, $body) && $note['createdAt'] > $anchor) {
                    $anchor = $note['createdAt'];
                } elseif (1 === preg_match(self::APPROVED_NOTE, $body)
                    && (!isset($approvedAt[$note['username']]) || $note['createdAt'] > $approvedAt[$note['username']])) {
                    $approvedAt[$note['username']] = $note['createdAt'];
                }
            }
        }

        foreach ($mr->reviewers as $reviewer) {
            if ('REQUESTED_CHANGES' === $reviewer['state'] && $counts($reviewer['username'], $reviewer['bot'])) {
                return ReviewVerdict::CHANGES;
            }
        }
        foreach ($mr->discussions as $discussion) {
            if (!$discussion->resolvable || $discussion->resolved) {
                continue;
            }
            foreach ($discussion->notes as $note) {
                if (!$note['system'] && $note['createdAt'] > $anchor && $counts($note['username'], $note['bot'])) {
                    return ReviewVerdict::CHANGES;
                }
            }
        }
        foreach ($mr->approvers as $approver) {
            if (!$counts($approver['username'], $approver['bot'])) {
                continue;
            }
            // No approval note read (an instance that writes none): the
            // current approval counts.
            $at = $approvedAt[$approver['username']] ?? null;
            if (null === $at || $at > $anchor) {
                return ReviewVerdict::APPROVED;
            }
        }

        return null;
    }
}
