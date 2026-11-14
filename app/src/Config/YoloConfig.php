<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * yolo.*: YOLO mode — after a state agent's plan, PABLO itself has it
 * implemented and committed (see Yolo\YoloPipeline). Off unless enabled;
 * a task can override it either way (Task::$yolo).
 */
final class YoloConfig
{
    public const DEFAULT_MAX_ROUNDS = 5;

    public function __construct(
        public readonly bool $enabled = false,
        public readonly int $maxRounds = self::DEFAULT_MAX_ROUNDS,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        return new self(
            enabled: (bool) ($project['yolo']['enabled'] ?? $defaults['yolo']['enabled'] ?? false),
            maxRounds: (int) ($project['yolo']['max_rounds'] ?? $defaults['yolo']['max_rounds'] ?? self::DEFAULT_MAX_ROUNDS),
        );
    }
}
