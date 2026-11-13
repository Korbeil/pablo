<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Config\ProjectConfig;
use Pablo\Support\Files;
use Pablo\Support\Json;

/**
 * Dispatcher health, persisted next to the run stamps (~/.pablo/stamps/):
 * one <project>.<job>.health JSON file per job and a global dispatch.health
 * for the last run. Written by the dispatcher (and reset by a manual
 * system:poll / sync:run), read by system:doctor and the dashboard banner.
 *
 * Best-effort: recording never throws into a dispatch run, and an unreadable
 * file reads as "no record". Atomic writes, UTC ISO-8601 timestamps.
 *
 * Back-off: from BACKOFF_AFTER consecutive failures on, a failing job waits
 * min(its interval, 5 min × 2^(failures-1)) after its last attempt instead of
 * being retried on every tick.
 */
final class DispatchHealth
{
    public const OUTCOME_RUNNING = 'running';
    public const OUTCOME_OK = 'ok';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PREFLIGHT_FAILED = 'preflight-failed';

    public const BACKOFF_AFTER = 2;
    public const BACKOFF_BASE_MINUTES = 5;
    public const ERROR_MAX_CHARS = 300;

    private const JOBS = ['sync', 'poll', 'main'];

    public static function jobPath(string $project, string $job): string
    {
        return Stamps::stampsDir()."/{$project}.{$job}.health";
    }

    public static function dispatchPath(): string
    {
        return Stamps::stampsDir().'/dispatch.health';
    }

    /** UTC ISO-8601, second precision. */
    public static function iso(float $at): string
    {
        return (new \DateTimeImmutable('@'.(int) $at))->format('Y-m-d\TH:i:sP');
    }

    public function read(string $project, string $job): ?JobHealth
    {
        $data = $this->readJson(self::jobPath($project, $job));
        if (null === $data) {
            return null;
        }

        return new JobHealth(
            project: $project,
            job: $job,
            lastAttemptAt: self::str($data, 'last_attempt_at'),
            lastSuccessAt: self::str($data, 'last_success_at'),
            lastError: self::str($data, 'last_error'),
            consecutiveFailures: self::int($data, 'consecutive_failures'),
            failingSince: self::str($data, 'failing_since'),
            checkFailures: self::int($data, 'check_failures'),
        );
    }

    /** A successful run (dispatched or manual): clears the failure streak and the back-off. */
    public function recordSuccess(string $project, string $job, float $at, int $checkFailures = 0): void
    {
        $this->writeJob(new JobHealth(
            project: $project,
            job: $job,
            lastAttemptAt: self::iso($at),
            lastSuccessAt: self::iso($at),
            checkFailures: $checkFailures,
        ));
    }

    public function recordFailure(string $project, string $job, float $at, string $error): void
    {
        try {
            $previous = $this->read($project, $job);
            $this->writeJob(new JobHealth(
                project: $project,
                job: $job,
                lastAttemptAt: self::iso($at),
                lastSuccessAt: $previous?->lastSuccessAt,
                lastError: self::truncate($error),
                consecutiveFailures: ($previous->consecutiveFailures ?? 0) + 1,
                failingSince: $previous->failingSince ?? self::iso($at),
            ));
        } catch (\Throwable) {
            // never throw into a dispatch run
        }
    }

    /**
     * Unix time from which a failing job may run again, or null when it is not
     * backing off (healthy, or fewer than BACKOFF_AFTER failures in a row).
     */
    public function retryAt(?JobHealth $health, int $intervalMinutes): ?float
    {
        if (null === $health || $health->consecutiveFailures < self::BACKOFF_AFTER || null === $health->lastAttemptAt) {
            return null;
        }
        $last = strtotime($health->lastAttemptAt);
        if (false === $last) {
            return null;
        }
        $exponent = min($health->consecutiveFailures - 1, 20);
        $delayMinutes = min(
            max($intervalMinutes, self::BACKOFF_BASE_MINUTES),
            self::BACKOFF_BASE_MINUTES * 2 ** $exponent,
        );

        return (float) ($last + $delayMinutes * 60);
    }

    /**
     * Every failing job of these projects, in project then job order.
     *
     * @param array<string, ProjectConfig> $projects
     *
     * @return list<JobHealth>
     */
    public function failing(array $projects): array
    {
        $failing = [];
        foreach ($projects as $cfg) {
            foreach (self::JOBS as $job) {
                $health = $this->read($cfg->name, $job);
                if (null !== $health && $health->failing()) {
                    $failing[] = $health;
                }
            }
        }

        return $failing;
    }

    public function startDispatch(float $at): void
    {
        $this->writeDispatch(new DispatchRecord(self::iso($at), null, self::OUTCOME_RUNNING));
    }

    public function finishDispatch(float $startedAt, float $finishedAt, string $outcome, ?string $detail = null): void
    {
        $this->writeDispatch(new DispatchRecord(
            self::iso($startedAt),
            self::iso($finishedAt),
            $outcome,
            null !== $detail ? self::truncate($detail) : null,
        ));
    }

    public function lastDispatch(): ?DispatchRecord
    {
        $data = $this->readJson(self::dispatchPath());
        $startedAt = null !== $data ? self::str($data, 'started_at') : null;
        if (null === $data || null === $startedAt) {
            return null;
        }

        return new DispatchRecord(
            $startedAt,
            self::str($data, 'finished_at'),
            self::str($data, 'outcome') ?? self::OUTCOME_RUNNING,
            self::str($data, 'detail'),
        );
    }

    /** One line, at most ERROR_MAX_CHARS characters. */
    public static function truncate(string $message): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $message));
        if (mb_strlen($flat) <= self::ERROR_MAX_CHARS) {
            return $flat;
        }

        return mb_substr($flat, 0, self::ERROR_MAX_CHARS - 1).'…';
    }

    private function writeJob(JobHealth $health): void
    {
        $this->write(self::jobPath($health->project, $health->job), [
            'last_attempt_at' => $health->lastAttemptAt,
            'last_success_at' => $health->lastSuccessAt,
            'last_error' => $health->lastError,
            'consecutive_failures' => $health->consecutiveFailures,
            'failing_since' => $health->failingSince,
            'check_failures' => $health->checkFailures,
        ]);
    }

    private function writeDispatch(DispatchRecord $record): void
    {
        $this->write(self::dispatchPath(), [
            'started_at' => $record->startedAt,
            'finished_at' => $record->finishedAt,
            'outcome' => $record->outcome,
            'detail' => $record->detail,
        ]);
    }

    /** @param array<string, mixed> $data */
    private function write(string $path, array $data): void
    {
        try {
            Files::write($path, json_encode($data, \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        } catch (\Throwable) {
            // best-effort: health recording never breaks a dispatch run
        }
    }

    /** @return array<string, mixed>|null */
    private function readJson(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = Files::tryRead($path);
        if (null === $raw) {
            return null;
        }
        $data = Json::tryDecodeArray($raw);
        if (!\is_array($data)) {
            return null;
        }

        /* @var array<string, mixed> $data */
        return $data;
    }

    /** @param array<string, mixed> $data */
    private static function str(array $data, string $key): ?string
    {
        return isset($data[$key]) && \is_string($data[$key]) && '' !== $data[$key] ? $data[$key] : null;
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        return isset($data[$key]) && \is_int($data[$key]) ? $data[$key] : 0;
    }
}
