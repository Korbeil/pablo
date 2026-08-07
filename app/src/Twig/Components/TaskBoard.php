<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\TaskView;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
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
    use DefaultActionTrait;

    /** How often the browser asks for a fresh render, in milliseconds. */
    public const POLL_MS = 30000;

    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    /** @return list<TaskView> */
    public function attention(): array
    {
        return $this->board()['attention'];
    }

    /** @return list<TaskView> */
    public function rest(): array
    {
        return $this->board()['rest'];
    }

    /** @var array{attention: list<TaskView>, rest: list<TaskView>}|null */
    private ?array $board = null;

    /** @return array{attention: list<TaskView>, rest: list<TaskView>} */
    private function board(): array
    {
        return $this->board ??= $this->dashboard->board();
    }
}
