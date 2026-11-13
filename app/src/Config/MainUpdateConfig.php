<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * main_update.*: whether and how often the primary checkout is updated.
 */
final class MainUpdateConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly int $intervalMinutes = 60,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        return new self(
            enabled: (bool) ($project['main_update']['enabled'] ?? $defaults['main_update']['enabled'] ?? true),
            intervalMinutes: (int) ($project['main_update']['interval_minutes'] ?? $defaults['main_update']['interval_minutes'] ?? 60),
        );
    }
}
