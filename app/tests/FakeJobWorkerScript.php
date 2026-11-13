<?php

declare(strict_types=1);

namespace Pablo\Tests;

/**
 * How one fake child behaves: running for $ticks isRunning() checks (null:
 * forever, a hung child), then exited with $exitCode and this output.
 */
final class FakeJobWorkerScript
{
    public function __construct(
        public ?int $ticks,
        public string $stdout,
        public string $stderr,
        public ?int $exitCode,
        public ?string $startError = null,
    ) {
    }
}
