<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\PollWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The poller countdown: one bar, plus a per-project recap.
 *
 * A single scheduler tick polls every due project at once, so one countdown to
 * the next tick is the honest picture — not one bar per project. The bar
 * tracks the soonest window; the recap carries each project's own last run (and
 * its cadence, for the case where a project overrides state_polling.interval_minutes
 * and therefore is not polled on that particular tick).
 *
 * The server re-renders on a slow poll and hands the browser two epoch
 * timestamps; the `countdown` Stimulus controller drains the bar smoothly in
 * between and re-syncs on every render.
 */
#[AsLiveComponent]
final class PollProgress
{
    use DefaultActionTrait;

    public const POLL_MS = 15000;

    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    /** @return list<PollWindow> */
    public function windows(): array
    {
        return $this->dashboard->pollWindows();
    }

    /** The window driving the bar: whichever project is polled next. */
    public function next(): ?PollWindow
    {
        return $this->windows()[0] ?? null;
    }

    /**
     * Projects ordered by how recently they ran, most recent first.
     *
     * @return list<PollWindow>
     */
    public function recap(): array
    {
        $windows = $this->windows();
        usort($windows, static fn (PollWindow $a, PollWindow $b) => [$b->lastRunAt, $a->project] <=> [$a->lastRunAt, $b->project]);

        return $windows;
    }
}
