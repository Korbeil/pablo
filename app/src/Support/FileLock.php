<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * An exclusive flock(2) taken with a deadline (polled with LOCK_NB, so a stuck
 * holder never hangs the caller); the kernel drops it when the holder exits.
 */
final class FileLock
{
    /**
     * The locked handle, or null when still held by another after $timeoutS
     * (0 = one non-blocking try). $onWait runs once, before the first wait.
     *
     * @param (\Closure(): void)|null $onWait
     *
     * @return resource|null
     *
     * @throws PabloError naming $path when the lock file cannot be opened
     */
    public static function acquire(string $path, float $timeoutS, int $pollUs = 50_000, ?\Closure $onWait = null)
    {
        Files::ensureDir(\dirname($path));
        $fd = @fopen($path, 'c+');
        if (false === $fd) {
            throw new PabloError("cannot open the lock file {$path}");
        }
        $deadline = microtime(true) + $timeoutS;
        $waiting = false;
        while (!flock($fd, \LOCK_EX | \LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($fd);

                return null;
            }
            if (!$waiting && null !== $onWait) {
                $onWait();
            }
            $waiting = true;
            usleep($pollUs);
        }

        return $fd;
    }

    /** @param resource|null $fd a handle from acquire(); a closed one or null is ignored */
    public static function release($fd): void
    {
        if (\is_resource($fd)) {
            flock($fd, \LOCK_UN);
            fclose($fd);
        }
    }
}
