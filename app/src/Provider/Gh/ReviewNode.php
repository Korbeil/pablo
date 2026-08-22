<?php

declare(strict_types=1);

namespace Pablo\Provider\Gh;

/**
 * One PR review node from the GraphQL timeline query.
 */
final readonly class ReviewNode
{
    public function __construct(
        public string $login,
        public string $typename,
        public string $state,
        public ?string $submittedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $node raw GraphQL node
     */
    public static function fromNode(array $node): self
    {
        return new self(
            (string) ($node['author']['login'] ?? ''),
            (string) ($node['author']['__typename'] ?? ''),
            (string) ($node['state'] ?? ''),
            isset($node['submittedAt']) ? (string) $node['submittedAt'] : null,
        );
    }

    public function isBot(): bool
    {
        return 'Bot' === $this->typename || str_ends_with($this->login, '[bot]');
    }
}
