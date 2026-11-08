<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Agents\Runtime\SessionRef;
use Pablo\Provider\Git\SyncReport;

/**
 * One presentation row of a sync log: the underlying report plus its
 * Bulma/Lucide styling.
 */
final readonly class RebaseLogRow
{
    public function __construct(
        public SyncReport $report,
        public string $color,
        public string $icon,
        public string $label,
        public string $emoji,
    ) {
    }

    /** How to resume the conflict-fix agent's session (see SessionRef), e.g. `opencode -s <id>`. */
    public function agentSessionRef(): string
    {
        return SessionRef::command($this->report->agentHandle);
    }
}
