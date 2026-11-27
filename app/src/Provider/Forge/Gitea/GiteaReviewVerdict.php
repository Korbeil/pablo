<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitea;

use Pablo\Provider\Forge\ReviewVerdict;

/**
 * The pure review evaluation behind GiteaForge::reviewVerdict(), Gitea's
 * take on site/content/states.html#reviews, GitHub's rules over Gitea's
 * reviews:
 *
 *  - only reviewers count: never the pull request's author, never a bot
 *    unless review.bot_whitelist names it, never a dismissed review nor a
 *    pending (unsubmitted) one;
 *  - the anchor is the last title change dropping the "WIP:" / "[WIP]"
 *    prefix (Gitea's draft), else the creation: a review submitted before
 *    it belongs to a previous round;
 *  - each reviewer's latest review after the anchor decides for them:
 *    "request changes" is changes; a comment review is changes while it
 *    still carries feedback (a non-empty body, or a comment nobody
 *    resolved — a bot's body is its summary, never feedback); "approve" is
 *    approved;
 *  - changes beat approvals.
 */
final class GiteaReviewVerdict
{
    /**
     * @param list<string> $botWhitelist
     *
     * @return ReviewVerdict::APPROVED|ReviewVerdict::CHANGES|null
     */
    public static function evaluate(GiteaReviews $pr, array $botWhitelist): ?string
    {
        $anchor = $pr->readyAt();
        $latest = [];
        foreach ($pr->reviews as $review) {
            $login = $review['login'];
            if ('' === $login || $login === $pr->author || $review['dismissed']) {
                continue;
            }
            if ($review['bot'] && !\in_array($login, $botWhitelist, true)) {
                continue;
            }
            if (!\in_array($review['state'], ['APPROVED', 'REQUEST_CHANGES', 'COMMENT'], true) || null === $review['submittedAt']) {
                continue;
            }
            $submitted = new \DateTimeImmutable($review['submittedAt']);
            if ($submitted <= $anchor) {
                continue;
            }
            $current = $latest[$login] ?? null;
            if (null === $current || $submitted > new \DateTimeImmutable((string) $current['submittedAt'])) {
                $latest[$login] = $review;
            }
        }

        $verdicts = [];
        foreach ($latest as $review) {
            if ('COMMENT' === $review['state']
                && ('' === $review['body'] || $review['bot'])
                && !isset($pr->unresolved[$review['id']])) {
                continue;
            }
            $verdicts[$review['state']] = true;
        }
        if (isset($verdicts['REQUEST_CHANGES']) || isset($verdicts['COMMENT'])) {
            return ReviewVerdict::CHANGES;
        }

        return isset($verdicts['APPROVED']) ? ReviewVerdict::APPROVED : null;
    }
}
