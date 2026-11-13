<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;

/**
 * sync.*: how and how often the task branches are synced.
 */
final class SyncConfig
{
    public function __construct(
        public readonly string $strategy,
        public readonly bool $autoApply,
        public readonly int $intervalMinutes,
    ) {
    }

    /**
     * Both arrays are already checked by ConfigSchema (see Config).
     *
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        $interval = ConfigValues::merged($project, $defaults, 'sync', 'interval_minutes');
        // 'rebase' or 'merge' (checked by the schema), when set.
        $strategy = ConfigValues::merged($project, $defaults, 'sync', 'strategy')
            ?? throw new PabloError('no value for sync.strategy — set it in the project config or ~/.pablo/config.yaml');

        return new self(
            strategy: (string) $strategy,
            autoApply: (bool) ConfigValues::merged($project, $defaults, 'sync', 'auto_apply'),
            intervalMinutes: (int) $interval,
        );
    }
}
