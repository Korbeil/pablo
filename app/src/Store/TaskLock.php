<?php

declare(strict_types=1);

namespace Pablo\Store;

/**
 * A held exclusive per-task lock; release() closes the fd (releasing flock).
 */
final class TaskLock
{
    public function __construct(private mixed $fd)
    {
    }

    public function __destruct()
    {
        $this->release();
    }

    public function release(): void
    {
        if (\is_resource($this->fd)) {
            fclose($this->fd);
        }
        $this->fd = null;
    }
}
