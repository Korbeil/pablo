<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Board;
use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\TaskView;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Both task tables, re-rendered on a poll.
 *
 * One Dashboard::board() call feeds both tables, and it reads only the
 * poller-written display cache — so a re-render is a handful of file reads,
 * never a gh/orca/tracker call.
 */
#[AsLiveComponent]
final class TaskBoard
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    /** How often the browser asks for a fresh render, in milliseconds. */
    public const POLL_MS = 30000;

    /** Project types the tabs offer; '' means "All projects". */
    public const TYPES = ['', 'work', 'open-source', 'personal'];

    /** Event broadcast to the other dashboard sections when the filter moves. */
    public const TYPE_CHANGED_EVENT = 'pablo:type-change';

    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    #[LiveProp(writable: true, url: true)]
    public string $type = '';

    /** @return list<TaskView> */
    public function attention(): array
    {
        return $this->board()->attention;
    }

    /** @return list<TaskView> */
    public function rest(): array
    {
        return $this->board()->rest;
    }

    /**
     * A tab was clicked: remember the filter and tell the poller + last-sync
     * sections so they re-render with the same projects.
     */
    #[LiveAction]
    public function setType(#[LiveArg('type')] string $type): void
    {
        $this->type = $type;
        $this->emit(self::TYPE_CHANGED_EVENT, ['type' => $type]);
    }

    private ?Board $board = null;

    private function board(): Board
    {
        return $this->board ??= $this->dashboard->board($this->dashboard->projectsOfType($this->type));
    }
}
