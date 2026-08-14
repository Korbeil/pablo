<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

/**
 * When a project was last polled and when it will next be polled.
 *
 * The dashboard renders this as a progress bar that drains to zero as
 * $nextRunAt approaches.
 */
final readonly class PollWindow
{
    public function __construct(
        public string $project,
        public ?\DateTimeImmutable $lastRunAt,
        public ?float $lastRunDurationS = null,
        public int $intervalMinutes = 10,
        public \DateTimeImmutable $nextRunAt = new \DateTimeImmutable('@0'),
        public \DateTimeImmutable $now = new \DateTimeImmutable('@0'),
    ) {
    }

    /** Never polled: there is no stamp for this project yet. */
    public function isFresh(): bool
    {
        return null === $this->lastRunAt;
    }

    /** Seconds until the next poll; 0 once it is due (the tick may still be pending). */
    public function secondsRemaining(): int
    {
        return max(0, $this->nextRunAt->getTimestamp() - $this->now->getTimestamp());
    }

    public function secondsSinceLastRun(): ?int
    {
        if (null === $this->lastRunAt) {
            return null;
        }

        return max(0, $this->now->getTimestamp() - $this->lastRunAt->getTimestamp());
    }

    /** Full span the progress bar represents, in seconds. Never 0. */
    public function spanSeconds(): int
    {
        $from = $this->lastRunAt?->getTimestamp() ?? $this->now->getTimestamp();

        return max(1, $this->nextRunAt->getTimestamp() - $from);
    }

    /** 100 right after a poll, 0 when the next one is due. */
    public function percentRemaining(): float
    {
        return round(min(100.0, max(0.0, $this->secondsRemaining() / $this->spanSeconds() * 100)), 2);
    }

    /** How long ago this project was last polled: "12m ago" / "never". */
    public function lastRunLabel(): string
    {
        $seconds = $this->secondsSinceLastRun();
        if (null === $seconds) {
            return 'never';
        }
        if ($seconds < 60) {
            return 'just now';
        }
        $minutes = intdiv($seconds, 60);
        if ($minutes < 60) {
            return "{$minutes}m ago";
        }
        $hours = intdiv($minutes, 60);

        return $hours < 24 ? "{$hours}h ago" : intdiv($hours, 24).'d ago';
    }

    /** "4m 12s" / "just now". */
    public function remainingLabel(): string
    {
        $s = $this->secondsRemaining();
        if (0 === $s) {
            return 'due now';
        }
        if ($s < 60) {
            return "{$s}s";
        }
        $m = intdiv($s, 60);
        if ($m < 60) {
            $rest = $s % 60;

            return 0 === $rest ? "{$m}m" : "{$m}m {$rest}s";
        }

        return intdiv($m, 60).'h '.($m % 60).'m';
    }

    /** How long the project's most recent poll run took: "12s" / "4m 12s" / "—". */
    public function lastRunDurationLabel(): string
    {
        if (null === $this->lastRunDurationS) {
            return '—';
        }
        $s = (int) round($this->lastRunDurationS);
        if ($s < 60) {
            return "{$s}s";
        }
        $m = intdiv($s, 60);
        $rest = $s % 60;

        return 0 === $rest ? "{$m}m" : "{$m}m {$rest}s";
    }
}
