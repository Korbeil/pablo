<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * testing.*: the manual testing step.
 */
final class TestingConfig
{
    public function __construct(
        public readonly bool $enabled = true,
        public readonly ?string $failureSignal = null,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        return new self(
            enabled: (bool) ($project['testing']['enabled'] ?? $defaults['testing']['enabled'] ?? true),
            failureSignal: $project['testing']['failure_signal'] ?? null,
        );
    }
}
