<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\RebaseLogView;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The last sync/rebase session per project. Reads one small JSON file per
 * project; re-renders every minute.
 */
#[AsLiveComponent]
final class RebaseLog
{
    use DefaultActionTrait;

    public const POLL_MS = 60000;

    public function __construct(
        private readonly RebaseLogView $view,
        private readonly Dashboard $dashboard,
    ) {
    }

    /** @return list<array{project: string, timestamp: ?string, strategy: string, reports: list<array<string, mixed>>}> */
    public function logs(): array
    {
        return $this->view->forProjects($this->dashboard->projects());
    }
}
