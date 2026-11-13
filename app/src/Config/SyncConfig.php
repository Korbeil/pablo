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
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults, string $file): self
    {
        $interval = ConfigValues::merged($project, $defaults, 'sync', 'interval_minutes');
        $strategy = ConfigValues::merged($project, $defaults, 'sync', 'strategy');
        if (!\in_array($strategy, ['rebase', 'merge'], true)) {
            throw new PabloError(\sprintf('%s: sync.strategy must be \'rebase\' or \'merge\', got %s', $file, var_export($strategy, true)));
        }

        return new self(
            strategy: (string) $strategy,
            autoApply: (bool) ConfigValues::merged($project, $defaults, 'sync', 'auto_apply'),
            intervalMinutes: (int) $interval,
        );
    }
}
