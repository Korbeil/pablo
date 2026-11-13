<?php

declare(strict_types=1);

namespace Pablo\Housekeeping;

use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use Pablo\Support\Files;
use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;

/**
 * Housekeeping of ~/.pablo (`pablo system:prune`, run daily at 03:00 by the
 * pablo-prune timer / com.pablo.prune agent). It only ever deletes whole
 * files whose content is all older than N days (`prune.keep_days` in
 * ~/.pablo/config.yaml, DEFAULT_KEEP_DAYS when unset, at least
 * MIN_KEEP_DAYS); it never trims inside a file — in three
 * categories, and nothing else: never a worktree, a task record, a stamp,
 * analytics, a backup or a sync log.
 *  - agent logs (agents/logs/*.log, one per run) not modified for N days,
 *    except the log of a run whose process is still alive;
 *  - the service logs written by launchd on macOS (SERVICE_LOGS, which
 *    launchd never rotates), rotated daily: each non-empty one is copied to
 *    one file per day, logs/archive/<name>.<YYYY-MM-DD> (the run's local
 *    date; a second run that day appends to it), then truncated in place.
 *    An archive is deleted once the day in its name ended more than N days
 *    ago (its mtime when the name has no date);
 *  - the compiled containers of other versions of the binary (see
 *    Kernel::getCacheDir()) whose newest file is older than N days.
 *    `system:install` drops them right away (staleContainers(null)).
 *
 * Paths are resolved per call (PABLO_* overrides and HOME honoured).
 */
final class Pruner
{
    /** Days of logs kept when prune.keep_days is unset. */
    public const DEFAULT_KEEP_DAYS = 10;
    /** The floor of prune.keep_days and --older-than: today's logs are always kept. */
    public const MIN_KEEP_DAYS = 1;

    /** The launchd service logs (see launchd/*.plist.template). */
    public const SERVICE_LOGS = ['dispatch.log', 'dispatch.err.log', 'prune.log', 'prune.err.log', 'web.log', 'web.err.log'];

    /** Where rotated service logs go, under logs/. */
    public const ARCHIVE_DIR = 'archive';

    /** How far before a legacy pidfile's started_at its log name's time may be. */
    private const LEGACY_LOG_SLACK_S = 120;

    /**
     * @param bool|null   $phar    test seam: whether running from the binary (default: PabloPaths::isPhar())
     * @param string|null $version test seam: the running version (default: ConsoleApplication::version())
     */
    public function __construct(
        private readonly ?bool $phar = null,
        private readonly ?string $version = null,
    ) {
    }

    /**
     * Everything that would be pruned now, without touching anything.
     *
     * @return list<PruneItem>
     *
     * @throws PabloError when $olderThanDays is below MIN_KEEP_DAYS
     */
    public function plan(int $olderThanDays = self::DEFAULT_KEEP_DAYS, ?float $now = null): array
    {
        if ($olderThanDays < self::MIN_KEEP_DAYS) {
            throw new PabloError('cannot keep fewer than '.self::MIN_KEEP_DAYS." day of logs, got {$olderThanDays}");
        }
        $now ??= microtime(true);
        $cutoff = $now - $olderThanDays * 86400;

        return [
            ...$this->agentLogs($cutoff),
            ...$this->serviceLogs($now),
            ...$this->logArchives($cutoff),
            ...$this->staleContainers($cutoff),
        ];
    }

    /**
     * @param list<PruneItem> $items
     *
     * @throws PabloError when a service log cannot be archived (it is then left untouched)
     */
    public function apply(array $items): void
    {
        foreach ($items as $item) {
            if (PruneItem::SERVICE_LOG === $item->kind && null !== $item->archive) {
                self::rotate($item->path, $item->archive);
            } else {
                Files::remove($item->path);
            }
        }
    }

    /** @return list<PruneItem> */
    public function agentLogs(float $cutoff): array
    {
        $agentsDir = self::agentsDir();
        [$liveLogs, $legacy] = $this->liveRuns($agentsDir);

        $items = [];
        foreach (Files::list($agentsDir.'/logs', '*.log') as $log) {
            if (!is_file($log) || is_link($log)) {
                continue;
            }
            $mtime = @filemtime($log);
            if (false === $mtime || $mtime >= $cutoff || isset($liveLogs[$log]) || self::matchesLegacyRun(basename($log), $legacy)) {
                continue;
            }
            $items[] = new PruneItem(PruneItem::AGENT_LOG, $log, (int) @filesize($log));
        }

        return $items;
    }

    /**
     * Every non-empty service log, to be moved into its archive of the day.
     *
     * @return list<PruneItem>
     */
    public function serviceLogs(float $now): array
    {
        $items = [];
        foreach (self::SERVICE_LOGS as $name) {
            $log = self::logsDir().'/'.$name;
            if (!is_file($log) || is_link($log)) {
                continue;
            }
            $size = (int) @filesize($log);
            if ($size > 0) {
                $archive = self::logsDir().'/'.self::ARCHIVE_DIR.'/'.$name.'.'.date('Y-m-d', (int) $now);
                $items[] = new PruneItem(PruneItem::SERVICE_LOG, $log, $size, $archive);
            }
        }

        return $items;
    }

    /**
     * Whole daily archives of the service logs whose content is all older
     * than $cutoff, never a part of one.
     *
     * @return list<PruneItem>
     */
    public function logArchives(float $cutoff): array
    {
        $items = [];
        foreach (self::SERVICE_LOGS as $name) {
            foreach (Files::list(self::logsDir().'/'.self::ARCHIVE_DIR, $name.'.*') as $archive) {
                if (!is_file($archive) || is_link($archive)) {
                    continue;
                }
                $newest = self::archiveNewest(substr(basename($archive), \strlen($name) + 1), $archive);
                if (null !== $newest && $newest < $cutoff) {
                    $items[] = new PruneItem(PruneItem::LOG_ARCHIVE, $archive, (int) @filesize($archive));
                }
            }
        }

        return $items;
    }

    /**
     * When an archive's newest content may have been written: the end of the
     * day in its name (a later run that day appends to it) — a copy or a
     * backup can change its mtime, never its name — else its mtime.
     */
    private static function archiveNewest(string $suffix, string $archive): ?float
    {
        $day = 1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $suffix)
            ? \DateTimeImmutable::createFromFormat('!Y-m-d', $suffix)
            : false;
        if (false !== $day && $day->format('Y-m-d') === $suffix) {
            return (float) $day->modify('+1 day')->getTimestamp();
        }
        $mtime = @filemtime($archive);

        return false === $mtime ? null : (float) $mtime;
    }

    /**
     * Containers compiled by other versions of the binary are dead weight:
     * each version compiles its own (see Kernel::getCacheDir()). Nothing from
     * a checkout, whose container lives in app/var/cache.
     *
     * @param float|null $cutoff only those whose newest file is older than this
     *                           timestamp; null: all of them (`system:install`)
     *
     * @return list<PruneItem>
     */
    public function staleContainers(?float $cutoff = null): array
    {
        if (!($this->phar ?? PabloPaths::isPhar())) {
            return [];
        }
        $version = $this->version ?? ConsoleApplication::version();
        $items = [];
        foreach (Files::list(Kernel::binaryCacheRoot(), '*', dirsOnly: true) as $dir) {
            if (basename($dir) === $version || is_link($dir)) {
                continue;
            }
            [$size, $newest] = self::sizeAndNewest($dir);
            if (null === $cutoff || $newest < $cutoff) {
                $items[] = new PruneItem(PruneItem::CONTAINER, $dir, $size);
            }
        }

        return $items;
    }

    public static function agentsDir(): string
    {
        return PabloPaths::pabloPath('agents', 'PABLO_AGENTS_DIR');
    }

    public static function logsDir(): string
    {
        return PabloPaths::pabloPath('logs', 'PABLO_LOGS_DIR');
    }

    /**
     * The runs whose process is still alive, from the pidfiles in agents/:
     * their log paths, and — for pidfiles written before the log path was
     * recorded — their label and start time.
     *
     * @return array{0: array<string, true>, 1: list<array{label: string, started: int}>}
     */
    private function liveRuns(string $agentsDir): array
    {
        $logs = [];
        $legacy = [];
        foreach (Files::list($agentsDir, '*.json') as $pidfile) {
            $record = Json::tryDecodeArray(Files::tryRead($pidfile) ?? '');
            if (!\is_array($record) || !isset($record['pid']) || !is_numeric($record['pid'])) {
                continue;
            }
            if (!@posix_kill((int) $record['pid'], 0)) {
                continue;
            }
            if (\is_string($record['log'] ?? null)) {
                $logs[$record['log']] = true;
                continue;
            }
            $started = \is_string($record['started_at'] ?? null) ? strtotime($record['started_at']) : false;
            $legacy[] = ['label' => (string) ($record['agent'] ?? ''), 'started' => false === $started ? 0 : $started];
        }

        return [$logs, $legacy];
    }

    /** @param list<array{label: string, started: int}> $legacy */
    private static function matchesLegacyRun(string $name, array $legacy): bool
    {
        if (1 !== preg_match('/^(\d+)-(.*)\.log$/', $name, $m)) {
            return false;
        }
        foreach ($legacy as $run) {
            if ($run['label'] === $m[2] && (int) $m[1] >= $run['started'] - self::LEGACY_LOG_SLACK_S) {
                return true;
            }
        }

        return false;
    }

    /**
     * Total size of $path and the mtime of its newest entry.
     *
     * @return array{0: int, 1: int}
     */
    private static function sizeAndNewest(string $path): array
    {
        $stat = @lstat($path);
        $newest = false === $stat ? 0 : $stat['mtime'];
        if (is_link($path) || !is_dir($path)) {
            return [(int) @filesize($path), $newest];
        }
        $total = 0;
        foreach (Files::list($path) as $child) {
            [$size, $mtime] = self::sizeAndNewest($child);
            $total += $size;
            $newest = max($newest, $mtime);
        }

        return [$total, $newest];
    }

    /**
     * Copy-truncate: append $path to $archive, then empty $path in place.
     * launchd keeps the file open in append mode, so it must stay the same
     * file (a rename would leave launchd writing to the old one). Nothing is
     * truncated unless the whole content reached the archive.
     *
     * @throws PabloError
     */
    private static function rotate(string $path, string $archive): void
    {
        $fh = @fopen($path, 'r+');
        if (false === $fh) {
            return;
        }
        try {
            flock($fh, \LOCK_EX);
            $copied = 0;
            // launchd does not take the lock: copy again whatever it appended
            // meanwhile, so the window before ftruncate stays minimal.
            while (($size = (int) (fstat($fh)['size'] ?? 0)) > $copied) {
                fseek($fh, $copied);
                $chunk = (string) stream_get_contents($fh, $size - $copied);
                if ('' === $chunk) {
                    break;
                }
                Files::ensureDir(\dirname($archive));
                if (\strlen($chunk) !== @file_put_contents($archive, $chunk, \FILE_APPEND | \LOCK_EX)) {
                    throw new PabloError("cannot archive {$path} into {$archive}: left as is");
                }
                $copied += \strlen($chunk);
            }
            if (0 === $copied) {
                return;
            }
            ftruncate($fh, 0);
            fflush($fh);
        } finally {
            flock($fh, \LOCK_UN);
            fclose($fh);
        }
    }
}
