<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Config\ProjectConfig;
use Pablo\Dispatch\Dispatch;

/**
 * Reads the dispatcher's per-project last-run stamps and works out when each
 * project will next be polled.
 *
 * The subtlety the CLI never had to model: one scheduler tick every
 * Dispatch::TICK_MINUTES fires the dispatcher, which *then* checks whether each
 * project is due. So a project is not polled at lastRun + interval — it is
 * polled at the first tick at or after that instant.
 */
final class PollSchedule
{
    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<PollWindow>
     */
    public function windows(array $projects, ?\DateTimeImmutable $now = null): array
    {
        $windows = [];
        foreach ($projects as $cfg) {
            $windows[] = $this->windowFor($cfg, $now);
        }
        usort($windows, static fn (PollWindow $a, PollWindow $b) => $a->nextRunAt <=> $b->nextRunAt);

        return $windows;
    }

    public function windowFor(ProjectConfig $cfg, ?\DateTimeImmutable $now = null): PollWindow
    {
        $now ??= new \DateTimeImmutable('now');
        $lastRunAt = $this->lastPollAt($cfg->name);

        // No stamp means the dispatcher has never polled this project, so it is
        // already due: the next tick will pick it up.
        $earliest = null !== $lastRunAt
            ? $lastRunAt->getTimestamp() + $cfg->pollInterval * 60
            : $now->getTimestamp();

        return new PollWindow(
            project: $cfg->name,
            lastRunAt: $lastRunAt,
            intervalMinutes: $cfg->pollInterval,
            nextRunAt: $this->nextTickAtOrAfter($earliest, $now),
            now: $now,
        );
    }

    /**
     * The most recent poll across all projects, or null if none ever ran.
     *
     * @param array<string, ProjectConfig> $projects
     */
    public function lastPollAcrossProjects(array $projects): ?\DateTimeImmutable
    {
        $latest = null;
        foreach ($projects as $cfg) {
            $at = $this->lastPollAt($cfg->name);
            if (null !== $at && (null === $latest || $at > $latest)) {
                $latest = $at;
            }
        }

        return $latest;
    }

    /**
     * The window driving the shared dashboard countdown bar.
     *
     * One scheduler tick polls every due project at once, so the bar is
     * anchored to the most recent poll across all projects (start) and drains
     * to the soonest upcoming poll (end). Anchoring the start to the last poll
     * *across* projects — rather than the focus project's own stamp — is what
     * makes the bar read full again right after a poll: when the focus
     * switches to a project whose window has already partially elapsed, the
     * bar resets to full instead of snapping to a partially-drained value.
     *
     * @param array<string, ProjectConfig> $projects
     */
    public function barWindow(array $projects, ?\DateTimeImmutable $now = null): ?PollWindow
    {
        $windows = $this->windows($projects, $now);
        if ([] === $windows) {
            return null;
        }

        $lastPoll = null;
        foreach ($windows as $window) {
            if (null !== $window->lastRunAt && (null === $lastPoll || $window->lastRunAt > $lastPoll)) {
                $lastPoll = $window->lastRunAt;
            }
        }

        $focus = $windows[0];

        return new PollWindow(
            project: 'all',
            lastRunAt: $lastPoll,
            intervalMinutes: $focus->intervalMinutes,
            nextRunAt: $focus->nextRunAt,
            now: $focus->now,
        );
    }

    public function lastPollAt(string $project): ?\DateTimeImmutable
    {
        $stamp = rtrim(Dispatch::stampsDir(), '/')."/{$project}.poll";
        if (!is_file($stamp)) {
            return null;
        }
        $raw = trim((string) file_get_contents($stamp));
        if ('' === $raw || !is_numeric($raw)) {
            return null;
        }

        return (new \DateTimeImmutable('@'.(int) (float) $raw))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()));
    }

    /**
     * Rounds a unix timestamp up to the next scheduler tick. Ticks sit on
     * wall-clock multiples of TICK_MINUTES, which unix multiples of the same
     * period track exactly (every real UTC offset is a multiple of 15 minutes).
     * A tick that has already passed still yields the next one.
     */
    private function nextTickAtOrAfter(int $earliest, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $period = Dispatch::TICK_MINUTES * 60;
        $target = max($earliest, $now->getTimestamp());
        $tick = (int) (ceil($target / $period) * $period);

        return (new \DateTimeImmutable('@'.$tick))
            ->setTimezone($now->getTimezone());
    }
}
