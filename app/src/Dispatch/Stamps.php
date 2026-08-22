<?php

declare(strict_types=1);

namespace Pablo\Dispatch;

/**
 * Per-job run stamps under ~/.pablo/stamps: when each project's sync/poll
 * last ran and how long it took. Shared by the dispatcher (write), the
 * terminal listing and the dashboard schedule view (read).
 */
final class Stamps
{
    public static function stampsDir(): string
    {
        $override = getenv('PABLO_STAMPS_DIR');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return (getenv('HOME') ?: '~').'/.pablo/stamps';
    }

    /** Encode a per-job run stamp: when it ran and how long the run took. */
    public function encodeStamp(float $ranAt, float $durationS): string
    {
        return json_encode([
            'ran_at' => $ranAt,
            'duration_s' => $durationS,
        ], \JSON_THROW_ON_ERROR);
    }

    /**
     * Read a per-job run stamp. Accepts the current JSON format as well as the
     * legacy bare-float format (duration unknown). Null when missing or
     * unparseable.
     */
    public function readStamp(string $project, string $job): ?RunStamp
    {
        $path = self::stampsDir()."/{$project}.{$job}";
        if (!is_file($path)) {
            return null;
        }
        $raw = trim((string) file_get_contents($path));
        if ('' === $raw) {
            return null;
        }
        if (str_starts_with($raw, '{')) {
            /** @var array<string, mixed>|null $data */
            $data = json_decode($raw, true);
            if (!\is_array($data) || !isset($data['ran_at']) || !is_numeric($data['ran_at'])) {
                return null;
            }

            return new RunStamp(
                (float) $data['ran_at'],
                isset($data['duration_s']) && is_numeric($data['duration_s'])
                    ? (float) $data['duration_s']
                    : null,
            );
        }
        if (!is_numeric($raw)) {
            return null;
        }

        return new RunStamp((float) $raw, null);
    }
}
