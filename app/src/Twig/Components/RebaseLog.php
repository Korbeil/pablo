<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\RebaseLogView;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * The last sync/rebase session per project. Reads one small JSON file per
 * project, so it renders with the page rather than polling.
 */
#[AsTwigComponent]
final class RebaseLog
{
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
