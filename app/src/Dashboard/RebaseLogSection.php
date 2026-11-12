<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Provider\Git\RebaseLog;

/**
 * One project's sync log, styled for rendering, with the notable events of
 * the earlier runs.
 */
final readonly class RebaseLogSection
{
    /**
     * @param list<RebaseLogRow>   $rows
     * @param list<RebaseLogEvent> $events newest first
     */
    public function __construct(
        public RebaseLog $log,
        public array $rows,
        public array $events = [],
    ) {
    }

    public function project(): string
    {
        return $this->log->project;
    }
}
