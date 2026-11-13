<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * review.*: the bots whose reviews count.
 */
final class ReviewConfig
{
    /**
     * @param list<string> $botWhitelist
     */
    public function __construct(
        public readonly array $botWhitelist = [],
    ) {
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        return new self(array_values((array) ConfigValues::merged($project, $defaults, 'review', 'bot_whitelist')));
    }
}
