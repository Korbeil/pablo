<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

/**
 * Whether the dispatcher's scheduler entry (systemd timer / launchd agent) is
 * installed by PABLO and currently active. Read by system:doctor.
 */
final readonly class SchedulerStatus
{
    public function __construct(
        public string $name,
        public bool $installed,
        public bool $active,
    ) {
    }
}
