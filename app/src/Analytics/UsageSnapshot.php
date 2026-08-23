<?php

declare(strict_types=1);

namespace Pablo\Analytics;

/**
 * Token/cost usage harvested from the local OpenCode CLI for one agent run.
 * All token fields follow OpenCode's own accounting (per assistant message,
 * summed): context = input + cache_read + cache_write, so cache hit rate is
 * derivable as cache_read / context without storing redundant ratios.
 */
final readonly class UsageSnapshot
{
    /**
     * @param list<string> $models unique model ids used during the run
     */
    public function __construct(
        public int $input,
        public int $output,
        public int $reasoning,
        public int $cacheRead,
        public int $cacheWrite,
        public int $totalTokens,
        public float $cost,
        public array $models,
        public string $sessionId,
        public ?int $spanMs,
        public string $quality, // "exact" | "window"
    ) {
    }
}
