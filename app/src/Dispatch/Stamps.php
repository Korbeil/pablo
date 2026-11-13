<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

use Pablo\Support\Files;
use Pablo\Support\Json;
use Pablo\Support\PabloPaths;

/**
 * Per-job run stamps under ~/.pablo/stamps: when each project's sync/poll
 * last ran and how long it took. Shared by the dispatcher (write), the
 * terminal listing and the dashboard schedule view (read).
 */
final class Stamps
{
    public static function stampsDir(): string
    {
        return PabloPaths::pabloPath('stamps', 'PABLO_STAMPS_DIR');
    }

    public static function path(string $project, string $job): string
    {
        return self::stampsDir()."/{$project}.{$job}";
    }

    /**
     * Record a per-job run: when it ran and how long it took. Best-effort
     * (never throws): a missing stamp only re-runs the job on the next tick.
     */
    public function write(string $project, string $job, float $ranAt, float $durationS): void
    {
        try {
            Files::write(self::path($project, $job), json_encode([
                'ran_at' => $ranAt,
                'duration_s' => $durationS,
            ], \JSON_THROW_ON_ERROR));
        } catch (\Throwable) {
            // best-effort, see above
        }
    }

    /** Null when missing or unparseable: its job then runs on the next tick, which rewrites it. */
    public function readStamp(string $project, string $job): ?RunStamp
    {
        $data = Json::tryDecodeArray(Files::tryRead(self::path($project, $job)) ?? '');
        if (null === $data || !isset($data['ran_at']) || !is_numeric($data['ran_at'])) {
            return null;
        }

        return new RunStamp(
            (float) $data['ran_at'],
            isset($data['duration_s']) && is_numeric($data['duration_s'])
                ? (float) $data['duration_s']
                : null,
        );
    }
}
