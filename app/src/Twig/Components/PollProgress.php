<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Dashboard\Dashboard;
use Pablo\Dashboard\PollWindow;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
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
 *
 * Follows the TaskBoard's project-type filter: when it moves, this section
 * re-renders with only the matching projects.
 */
#[AsLiveComponent]
final class PollProgress
{
    use DefaultActionTrait;

    public const POLL_MS = 15000;

    public function __construct(private readonly Dashboard $dashboard)
    {
    }

    #[LiveProp(url: true)]
    public string $type = '';

    #[LiveListener(TaskBoard::TYPE_CHANGED_EVENT)]
    public function onTypeChange(#[LiveArg('type')] string $type): void
    {
        $this->type = $type;
    }

    /** @return list<PollWindow> */
    public function windows(): array
    {
        return $this->dashboard->pollWindows($this->dashboard->projectsOfType($this->type));
    }

    /**
     * The window driving the shared countdown bar.
     *
     * One scheduler tick polls every due project at once, so the single bar is
     * anchored to the most recent poll across the visible projects and drains
     * to the soonest upcoming poll. Anchoring the start to the last poll
     * *across* projects (rather than the focus project's own stamp) is what
     * makes the bar read full again right after a poll — otherwise, when the
     * focus switches to a project whose window has already partially elapsed,
     * the bar would snap to a partially-drained value instead of resetting.
     */
    public function bar(): ?PollWindow
    {
        return $this->dashboard->barWindow($this->dashboard->projectsOfType($this->type));
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
