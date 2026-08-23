<?php

declare(strict_types=1);

namespace Pablo\Analytics;

/**
 * Everything known about one concluded agent run, assembled by the
 * internal:watch-agent subprocess for agentRunFinished().
 */
final readonly class AgentRunRecord
{
    public function __construct(
        public string $project,
        public string $branch,
        public string $runId,
        public string $agent,
        public string $backend,
        public ?string $startedAt,
        public string $finishedAt,
        public ?int $durationS,
        public ?UsageSnapshot $usage,
    ) {
    }
}
