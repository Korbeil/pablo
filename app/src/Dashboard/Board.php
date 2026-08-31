<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

/**
 * The three dashboard task tables: tasks needing attention, tasks with a
 * working agent, and everything else.
 */
final readonly class Board
{
    /**
     * @param list<TaskView> $attention
     * @param list<TaskView> $working
     * @param list<TaskView> $rest
     */
    public function __construct(
        public array $attention,
        public array $working,
        public array $rest,
    ) {
    }
}
