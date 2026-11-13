<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * state_polling.*: how often the task states are polled.
 */
final class StatePollingConfig
{
    public function __construct(
        public readonly int $intervalMinutes,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        return new self((int) ConfigValues::merged($project, $defaults, 'state_polling', 'interval_minutes'));
    }
}
