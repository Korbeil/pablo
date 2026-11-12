<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

/**
 * One notable report from an earlier sync run (see SyncHistory), shown under
 * a project's last sync so a conflict or a lease failure stays visible after
 * a later clean sync.
 */
final readonly class RebaseLogEvent
{
    public function __construct(
        public ?string $timestamp,
        public bool $apply,
        public RebaseLogRow $row,
    ) {
    }
}
