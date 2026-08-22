<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Provider\Git\RebaseLog;

/**
 * One project's sync log, styled for rendering.
 */
final readonly class RebaseLogSection
{
    /**
     * @param list<RebaseLogRow> $rows
     */
    public function __construct(
        public RebaseLog $log,
        public array $rows,
    ) {
    }

    public function project(): string
    {
        return $this->log->project;
    }
}
