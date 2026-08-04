<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * A user-facing PABLO failure: printed to stderr, exits non-zero.
 */
class PabloError extends \RuntimeException
{
}
