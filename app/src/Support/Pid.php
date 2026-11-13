<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Whether a process is still alive, with or without ext-posix (only
 * suggested in composer.json): `posix_kill($pid, 0)` when available, else
 * `kill -0 <pid>`. Never call posix_kill() directly — `@` does not silence
 * a call to an undefined function.
 */
final class Pid
{
    public static function isAlive(int $pid): bool
    {
        // pid 0 / negative pids address process groups: never "a run".
        if ($pid <= 0) {
            return false;
        }
        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return self::killZero($pid);
    }

    /** `kill -0 <pid>`: isAlive() without ext-posix (public so tests reach it on a PHP that has posix). */
    public static function killZero(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        exec('kill -0 '.$pid.' 2>/dev/null', $output, $code);

        return 0 === $code;
    }
}
