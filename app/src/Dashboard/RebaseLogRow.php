<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

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
}
