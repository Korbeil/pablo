<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Lenient numbers out of decoded JSON / YAML: anything not numeric (missing
 * key, null, garbage) counts as zero, so a malformed record never throws
 * into analytics or usage harvesting.
 */
final class Numbers
{
    public static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    public static function float(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
