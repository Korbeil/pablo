<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge\Gitlab;

/**
 * One merge request discussion: a thread (resolvable) or a lone note,
 * system notes included.
 */
final readonly class GitlabDiscussion
{
    /**
     * @param list<array{username: string, bot: bool, system: bool, body: string, createdAt: \DateTimeImmutable}> $notes oldest first
     */
    public function __construct(
        public bool $resolvable,
        public bool $resolved,
        public array $notes,
    ) {
    }

    /** @param array<mixed> $node raw GraphQL Discussion node */
    public static function fromNode(array $node): self
    {
        $notes = [];
        foreach (GitlabReviews::nodes($node['notes'] ?? null) as $note) {
            $createdAt = $note['createdAt'] ?? null;
            if (!\is_string($createdAt)) {
                continue;
            }
            $notes[] = [
                'username' => (string) ($note['author']['username'] ?? ''),
                'bot' => true === ($note['author']['bot'] ?? false),
                'system' => true === ($note['system'] ?? false),
                'body' => (string) ($note['body'] ?? ''),
                'createdAt' => new \DateTimeImmutable($createdAt),
            ];
        }

        return new self(true === ($node['resolvable'] ?? false), true === ($node['resolved'] ?? false), $notes);
    }
}
