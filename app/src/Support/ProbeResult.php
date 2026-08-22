<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Outcome of a failure-tolerant command probe: exit code plus combined
 * stdout+stderr output.
 */
final readonly class ProbeResult
{
    public function __construct(
        public int $exitCode,
        public string $output,
    ) {
    }
}
