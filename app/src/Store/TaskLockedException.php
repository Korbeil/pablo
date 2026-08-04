<?php

declare(strict_types=1);

namespace Pablo\Store;

use Pablo\Support\PabloError;

/**
 * Raised when a per-task flock cannot be acquired within its deadline.
 */
final class TaskLockedException extends PabloError
{
}
