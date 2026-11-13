<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * The pure review evaluation behind GhPrInterface::reviewVerdict(): no gh
 * call, so every rule of site/content/states.html#reviews is tested directly.
 */
final class ReviewVerdict
{
    public const APPROVED = 'approved';
    public const CHANGES = 'changes';

    /**
     * @param list<ReviewNode>   $reviews
     * @param list<ReviewThread> $threads      — unresolved threads keep a comment
     *                                         review counting as changes; a comment review whose
     *                                         threads are all resolved and whose body is empty
     *                                         counts as addressed
     * @param list<string>       $botWhitelist
     *
     * @return self::APPROVED|self::CHANGES|null
     */
    public static function evaluate(array $reviews, array $threads, \DateTimeImmutable $anchor, string $author, array $botWhitelist): ?string
    {
        // GraphQL spells a bot's login without the "[bot]" suffix the REST
        // API and the GitHub UI show: accept both spellings in the whitelist.
        $botLogin = static fn (string $login): string => str_ends_with($login, '[bot]') ? substr($login, 0, -5) : $login;
        $allowedBots = array_map($botLogin, $botWhitelist);
        $latest = [];
        foreach ($reviews as $node) {
            $reviewAuthor = $node->login;
            if ('' === $reviewAuthor || $reviewAuthor === $author) {
                continue;
            }
            if ($node->isBot() && !\in_array($botLogin($reviewAuthor), $allowedBots, true)) {
                continue;
            }
            if (!\in_array($node->state, ['APPROVED', 'CHANGES_REQUESTED', 'COMMENTED'], true)) {
                continue;
            }
            $submitted = $node->submittedAt;
            if (null === $submitted || new \DateTimeImmutable($submitted) <= $anchor) {
                continue;
            }
            $current = $latest[$reviewAuthor] ?? null;
            if (null === $current || new \DateTimeImmutable($submitted) > new \DateTimeImmutable((string) $current->submittedAt)) {
                $latest[$reviewAuthor] = $node;
            }
        }
        // A comment review only counts while it still carries feedback: a
        // non-empty review body (no thread to resolve) or at least one comment
        // in an unresolved thread. When every thread is resolved, the review
        // counts as addressed. CHANGES_REQUESTED is an explicit verdict on its
        // own and ignores thread state. An unknown review id (never in
        // production) keeps counting rather than silently dropping the signal.
        // A bot's review body is its summary (Copilot writes one on every
        // review, "approval recommended" included), not feedback to address:
        // a whitelisted bot's comment review counts only through its
        // unresolved threads.
        $unresolved = [];
        foreach ($threads as $thread) {
            if ($thread->resolved) {
                continue;
            }
            foreach ($thread->reviewIds as $reviewId) {
                $unresolved[$reviewId] = true;
            }
        }
        $verdicts = [];
        foreach ($latest as $node) {
            $body = $node->isBot() ? '' : $node->body;
            if ('COMMENTED' === $node->state && '' === $body && '' !== $node->id
                && !isset($unresolved[$node->id])) {
                continue;
            }
            $verdicts[$node->state] = true;
        }
        if (isset($verdicts['CHANGES_REQUESTED']) || isset($verdicts['COMMENTED'])) {
            return self::CHANGES;
        }
        if (isset($verdicts['APPROVED'])) {
            return self::APPROVED;
        }

        return null;
    }
}
