<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitlab;

use Pablo\Provider\Forge\ReviewSnapshot;

/**
 * Everything GitlabForge::reviewVerdict() reads of one merge request: its
 * author and creation, who approves it now, each reviewer's current review
 * state, and every discussion (system notes included: they carry the
 * "marked as ready" and "approved" events).
 */
final readonly class GitlabReviews implements ReviewSnapshot
{
    /**
     * @param list<array{username: string, bot: bool}>                $approvers
     * @param list<array{username: string, bot: bool, state: string}> $reviewers   state: MergeRequestReviewState (REQUESTED_CHANGES…)
     * @param list<GitlabDiscussion>                                  $discussions
     */
    public function __construct(
        public string $author,
        public \DateTimeImmutable $createdAt,
        public array $approvers,
        public array $reviewers,
        public array $discussions,
    ) {
    }

    public function verdict(array $botWhitelist): ?string
    {
        return GitlabReviewVerdict::evaluate($this, $botWhitelist);
    }

    /**
     * From a GraphQL MergeRequest node (createdAt, author, approvedBy,
     * reviewers) and its discussion nodes; null when a field is missing.
     *
     * @param array<mixed>       $mr
     * @param list<array<mixed>> $discussions
     */
    public static function fromGraphql(array $mr, array $discussions): ?self
    {
        $author = $mr['author']['username'] ?? null;
        $createdAt = $mr['createdAt'] ?? null;
        if (!\is_string($author) || !\is_string($createdAt)) {
            return null;
        }
        $approvers = [];
        foreach (self::nodes($mr['approvedBy'] ?? null) as $user) {
            $approvers[] = ['username' => (string) ($user['username'] ?? ''), 'bot' => true === ($user['bot'] ?? false)];
        }
        $reviewers = [];
        foreach (self::nodes($mr['reviewers'] ?? null) as $user) {
            $reviewers[] = [
                'username' => (string) ($user['username'] ?? ''),
                'bot' => true === ($user['bot'] ?? false),
                'state' => strtoupper((string) ($user['mergeRequestInteraction']['reviewState'] ?? '')),
            ];
        }

        return new self(
            $author,
            new \DateTimeImmutable($createdAt),
            $approvers,
            $reviewers,
            array_map(GitlabDiscussion::fromNode(...), $discussions),
        );
    }

    /**
     * The array nodes of a GraphQL connection.
     *
     * @return list<array<mixed>>
     */
    public static function nodes(mixed $connection): array
    {
        $nodes = \is_array($connection) ? ($connection['nodes'] ?? null) : null;

        return \is_array($nodes) ? array_values(array_filter($nodes, \is_array(...))) : [];
    }
}
