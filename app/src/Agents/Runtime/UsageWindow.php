<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Domain\Time;

/**
 * The launch window the usage harvesters reading per-session logs share:
 * candidates are the logs touched since the launch (minus a small grace)
 * whose first prompt is not older than that, oldest first; the run is the
 * one whose first prompt hashes to the launch's fingerprint ("exact"),
 * else the first few candidates merged ("window").
 */
final class UsageWindow
{
    public const GRACE_S = 5;

    /** Start of the window, in Unix seconds. */
    public static function startS(Time $time, string $launchedAt): int
    {
        return $time->parseTs($launchedAt)->getTimestamp() - self::GRACE_S;
    }

    /**
     * The sessions read from $paths that fall in the window, oldest first.
     * $read returns null for a log that is not a candidate.
     *
     * @template T of array{startedS: int, fingerprint: string}
     *
     * @param list<string>               $paths
     * @param callable(string): (T|null) $read
     *
     * @return list<T>
     */
    public static function candidates(array $paths, int $windowStartS, callable $read): array
    {
        $candidates = [];
        foreach ($paths as $path) {
            if ((int) @filemtime($path) < $windowStartS) { // best-effort: a vanished log is skipped
                continue;
            }
            $session = $read($path);
            if (null === $session || $session['startedS'] < $windowStartS) {
                continue;
            }
            $candidates[] = $session;
        }
        usort($candidates, static fn (array $a, array $b): int => $a['startedS'] <=> $b['startedS']);

        return $candidates;
    }

    /**
     * The session whose fingerprint is $fingerprint, if any.
     *
     * @template T of array{startedS: int, fingerprint: string}
     *
     * @param list<T> $candidates
     *
     * @return T|null
     */
    public static function exact(array $candidates, ?string $fingerprint): ?array
    {
        if (null === $fingerprint || '' === $fingerprint) {
            return null;
        }
        foreach ($candidates as $i => $session) {
            if ($session['fingerprint'] === $fingerprint) {
                return $candidates[$i]; // as given, not the copy narrowed by the comparison
            }
        }

        return null;
    }

    /** An ISO-8601 timestamp in Unix milliseconds, 0 when missing or malformed. */
    public static function timestampMs(Time $time, mixed $ts): int
    {
        if (!\is_string($ts) || '' === $ts) {
            return 0;
        }
        try {
            return (int) round((float) $time->parseTs($ts)->format('U.u') * 1000);
        } catch (\Exception) { // a malformed timestamp
            return 0;
        }
    }
}
