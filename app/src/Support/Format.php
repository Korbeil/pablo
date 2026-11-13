<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * The compact analytics number formats shared by the terminal
 * (`pablo stats`, `task:history`) and the dashboard's Twig filters, so
 * both print "1h20m" and "12k" the same way.
 */
final class Format
{
    /** Compact human duration; -1 input renders as "-" (no data). */
    public static function duration(int $seconds, bool $allowDays = false, bool $noData = false): string
    {
        if ($noData || $seconds < 0) {
            return '-';
        }
        if ($seconds < 60) {
            return "{$seconds}s";
        }
        $minutes = (int) floor($seconds / 60);
        if ($minutes < 60) {
            return "{$minutes}m";
        }
        $hours = (int) floor($minutes / 60);
        $remMinutes = $minutes % 60;
        if ($hours < 48 || !$allowDays) {
            return 0 === $remMinutes ? "{$hours}h" : "{$hours}h{$remMinutes}m";
        }
        $days = (int) floor($hours / 24);

        return $days.'d'.($hours % 24).'h';
    }

    public static function tokens(int $tokens): string
    {
        if ($tokens >= 1_000_000) {
            return \sprintf('%.1fM', $tokens / 1_000_000);
        }
        if ($tokens >= 10_000) {
            return \sprintf('%.0fk', $tokens / 1_000);
        }
        if ($tokens >= 1_000) {
            return \sprintf('%.1fk', $tokens / 1_000);
        }

        return (string) $tokens;
    }
}
