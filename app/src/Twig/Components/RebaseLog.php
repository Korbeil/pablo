<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\RebaseLogSection;
use Pablo\Dashboard\RebaseLogView;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The last sync/rebase session per project, plus the notable events of the
 * earlier runs. Reads two small local files per project (the last log and
 * the sync history); re-renders every minute.
 *
 * Follows the TaskBoard's project-type filter: when it moves, this section
 * re-renders with only the matching projects.
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

    #[LiveProp(url: true)]
    public string $type = '';

    #[LiveListener(TaskBoard::TYPE_CHANGED_EVENT)]
    public function onTypeChange(#[LiveArg('type')] string $type): void
    {
        $this->type = $type;
    }

    /** @return list<RebaseLogSection> */
    public function logs(): array
    {
        return $this->view->forProjects($this->dashboard->projectsOfType($this->type));
    }
}
