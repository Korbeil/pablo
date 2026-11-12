<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

use Pablo\Support\Files;

/**
 * Per-project sync history: ~/.pablo/logs/sync-history-<project>.jsonl, one
 * run per line (oldest first), capped at the last $cap runs.
 *
 * rebase-last-<project>.json only keeps what the latest sync did, so a
 * conflict or a lease failure followed by a clean sync would otherwise be
 * lost. The log directory is resolved per call (Sync::logsDir(), never at
 * container compile time).
 *
 * Writing is best-effort: append() never throws, so a history failure can
 * never fail a sync. Reading skips unreadable lines.
 */
final class SyncHistory
{
    public const DEFAULT_CAP = 200;

    /** How long append() waits for a concurrent writer (dispatch vs CLI). */
    private const LOCK_TIMEOUT_S = 5;

    public function __construct(private readonly int $cap = self::DEFAULT_CAP)
    {
    }

    public static function path(string $project): string
    {
        return Sync::logsDir()."/sync-history-{$project}.jsonl";
    }

    public function append(SyncRun $run): void
    {
        try {
            $line = json_encode($run->toArray(), \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
            $path = self::path($run->log->project);
            Files::mkdir(\dirname($path));
            $lock = @fopen($path.'.lock', 'c');
            if (false === $lock) {
                return;
            }
            try {
                if (!$this->lock($lock)) {
                    return;
                }
                $lines = is_file($path) ? (@file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: []) : [];
                $lines[] = $line;
                $lines = \array_slice($lines, -max(1, $this->cap));
                // Rewritten whole (at most $cap short lines) and renamed into
                // place, so a reader never sees a half-written file.
                Files::write($path, implode("\n", $lines)."\n");
            } finally {
                @flock($lock, \LOCK_UN);
                fclose($lock);
            }
        } catch (\Throwable) {
            // best-effort: the history must never fail a sync
        }
    }

    /**
     * Past runs, newest first. With $only, keeps the runs that have at least
     * one report with one of those actions, restricted to those reports.
     *
     * @param list<string> $only
     *
     * @return list<SyncRun>
     */
    public function read(string $project, ?int $limit = null, array $only = []): array
    {
        $path = self::path($project);
        if (!is_file($path)) {
            return [];
        }
        $lines = @file($path, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES) ?: [];
        $runs = [];
        foreach (array_reverse($lines) as $line) {
            $data = json_decode($line, true);
            if (!\is_array($data) || !\is_array($data['reports'] ?? null)
                || [] !== array_filter($data['reports'], static fn (mixed $r): bool => !\is_array($r))) {
                continue; // corrupt or truncated line
            }
            /** @var array<string, mixed> $data */
            $run = SyncRun::fromArray($data)->only($only);
            if ([] !== $only && [] === $run->log->reports) {
                continue;
            }
            $runs[] = $run;
            if (null !== $limit && \count($runs) >= $limit) {
                break;
            }
        }

        return $runs;
    }

    /** @param resource $handle */
    private function lock($handle): bool
    {
        $deadline = microtime(true) + self::LOCK_TIMEOUT_S;
        do {
            if (flock($handle, \LOCK_EX | \LOCK_NB)) {
                return true;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        return false;
    }
}
