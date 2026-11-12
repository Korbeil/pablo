<?php

declare(strict_types=1);

namespace Pablo\Analytics;

/**
 * Reads the JSONL analytics event log back for aggregation (pablo stats and,
 * later, dashboard panels). Events are plain payload arrays in ts order
 * across all project files; malformed lines are skipped silently — the log
 * is best-effort by contract.
 */
final class AnalyticsReader
{
    /**
     * All recorded events, oldest first. With $since, monthly files
     * (<YYYY-MM>.jsonl, named after the UTC month they were written in) that
     * end before $since's month are not even opened; the caller still filters
     * events by timestamp within the overlapping months. A file not named
     * after a month is always read.
     *
     * @return list<array<string, mixed>>
     */
    public function read(?string $project = null, ?\DateTimeImmutable $since = null): array
    {
        $firstMonth = $since?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m');
        $events = [];
        foreach (glob(JsonlAnalytics::root().'/*/*.jsonl') ?: [] as $file) {
            if (null !== $project && basename(\dirname($file)) !== $project) {
                continue;
            }
            $month = basename($file, '.jsonl');
            if (null !== $firstMonth && 1 === preg_match('/^\d{4}-\d{2}$/', $month) && $month < $firstMonth) {
                continue;
            }
            $lines = @file($file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
            if (false === $lines) {
                continue;
            }
            foreach ($lines as $line) {
                $decoded = json_decode($line, true);
                if (\is_array($decoded)) {
                    $events[] = $decoded;
                }
            }
        }
        usort($events, static fn (array $a, array $b) => strcmp((string) ($a['ts'] ?? ''), (string) ($b['ts'] ?? '')));

        return $events;
    }
}
