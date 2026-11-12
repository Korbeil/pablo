<?php

declare(strict_types=1);

namespace Pablo\Doctor;

use Pablo\Config\ProjectConfig;
use Pablo\Dispatch\Dispatch;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Install\Scheduler\SchedulerFactory;

/**
 * The "scheduler" section of system:doctor: is the dispatcher's timer
 * installed and active, did it run recently, and which jobs keep failing
 * (read from DispatchHealth). Every finding is a warning: it never changes
 * the doctor's exit code.
 */
final class SchedulerHealth
{
    /** Three missed ticks: the scheduler is not firing. */
    public const STALE_DISPATCH_MINUTES = 15;

    public function __construct(
        private readonly SchedulerFactory $schedulers,
        private readonly DispatchHealth $health,
    ) {
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<string>
     */
    public function lines(array $projects, ?float $now = null): array
    {
        $now ??= microtime(true);
        $lines = [];

        try {
            $status = $this->schedulers->create()->dispatchStatus();
            if (!$status->installed) {
                $lines[] = self::line(false, 'scheduler', "{$status->name} is not installed");
                $lines[] = '   → run: pablo system:install';
            } elseif (!$status->active) {
                $lines[] = self::line(false, 'scheduler', "{$status->name} is installed but not active");
                $lines[] = '   → run: pablo system:install';
            } else {
                $lines[] = self::line(true, 'scheduler', "{$status->name} active");
            }
        } catch (\Throwable $e) {
            $lines[] = self::line(false, 'scheduler', $e->getMessage());
        }

        $last = $this->health->lastDispatch();
        if (null === $last) {
            $lines[] = self::line(false, 'dispatch', 'no dispatch recorded yet');
        } else {
            $started = strtotime($last->startedAt);
            $minutes = false !== $started ? (int) floor(max(0.0, $now - $started) / 60) : null;
            $ago = null !== $minutes ? "{$minutes} min ago" : $last->startedAt;
            $stale = null === $minutes || $minutes > self::STALE_DISPATCH_MINUTES;
            $detail = "last dispatch {$ago} ({$last->outcome})";
            if (DispatchHealth::OUTCOME_PREFLIGHT_FAILED === $last->outcome && null !== $last->detail) {
                $detail .= ": {$last->detail}";
            }
            $lines[] = self::line(!$stale && DispatchHealth::OUTCOME_PREFLIGHT_FAILED !== $last->outcome, 'dispatch', $detail);
            if ($stale) {
                $lines[] = '   → the dispatcher runs every '.Dispatch::TICK_MINUTES.' minutes: check the scheduler and its logs';
            }
        }

        foreach ($this->health->failing($projects) as $job) {
            $since = null !== $job->failingSince ? self::display($job->failingSince) : '?';
            $detail = "{$job->project} {$job->job} failing since {$since} ({$job->consecutiveFailures}×): ".($job->lastError ?? 'unknown error');
            $lines[] = self::line(false, 'job', $detail);
            $cfg = $projects[$job->project] ?? null;
            $intervals = Dispatch::jobIntervals();
            if (null !== $cfg && isset($intervals[$job->job])) {
                $retryAt = $this->health->retryAt($job, $intervals[$job->job]($cfg));
                if (null !== $retryAt && $now < $retryAt) {
                    $lines[] = '   → backing off: next try after '.self::display(DispatchHealth::iso($retryAt)).' (a manual run resets it)';
                }
            }
        }
        foreach ($projects as $cfg) {
            $poll = $this->health->read($cfg->name, 'poll');
            if (null !== $poll && !$poll->failing() && $poll->checkFailures > 0) {
                $lines[] = self::line(false, 'job', "{$cfg->name} poll: {$poll->checkFailures} task check(s) failed on the last run");
            }
        }

        return $lines;
    }

    private static function line(bool $ok, string $label, string $detail): string
    {
        return \sprintf('%s %-10s %s', $ok ? '✅' : '⚠️', $label, $detail);
    }

    /** An ISO-8601 timestamp in the local timezone, minute precision. */
    private static function display(string $iso): string
    {
        $ts = strtotime($iso);
        if (false === $ts) {
            return $iso;
        }

        return (new \DateTimeImmutable('@'.$ts))
            ->setTimezone(new \DateTimeZone(date_default_timezone_get()))
            ->format('Y-m-d H:i T');
    }
}
