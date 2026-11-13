<?php

declare(strict_types=1);

namespace Pablo\Tests;

/**
 * Collision-proof temp paths for tests. `uniqid()` is only a microsecond
 * clock: two processes (parallel suites from several worktrees on a loaded
 * machine) can get the same value and then share — or fail to `mkdir` — the
 * same directory. Here the name carries 64 random bits, and the path is
 * created atomically (`mkdir` / `fopen('x')` fail on an existing path), with
 * a retry on collision, so the returned path is always this caller's own.
 */
final class TempDir
{
    private const ATTEMPTS = 10;

    /** Creates a new empty directory `<tmp>/pablo-<prefix>-<random>` and returns its path. */
    public static function create(string $prefix): string
    {
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $path = self::candidate($prefix);
            if (@mkdir($path, 0o777, true)) { // checked: false on collision, retried
                return $path;
            }
        }

        throw new \RuntimeException(\sprintf('Cannot create a unique temp directory for "%s" in %s.', $prefix, sys_get_temp_dir()));
    }

    /** Creates a new empty file `<tmp>/pablo-<prefix>-<random><suffix>` and returns its path. */
    public static function file(string $prefix, string $suffix = ''): string
    {
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $path = self::candidate($prefix).$suffix;
            $handle = @fopen($path, 'x'); // checked: false on collision, retried
            if (false !== $handle) {
                fclose($handle);

                return $path;
            }
        }

        throw new \RuntimeException(\sprintf('Cannot create a unique temp file for "%s" in %s.', $prefix, sys_get_temp_dir()));
    }

    private static function candidate(string $prefix): string
    {
        return sys_get_temp_dir().'/pablo-'.$prefix.'-'.bin2hex(random_bytes(8));
    }
}
