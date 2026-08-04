<?php

declare(strict_types=1);

namespace Pablo\Domain;

use Pablo\Poller\Poller;

/**
 * Poller-written display cache so `pablo tasks` renders instantly without
 * live CLI calls. Null means "never polled yet" (listing falls back to a
 * live fetch).
 */
final class DisplayCache
{
    public function __construct(
        public readonly ?string $trackerStatus = null,
        public readonly ?string $prState = null,
        public readonly ?int $agentCount = null,
        public readonly ?string $agentActivity = null,
        public readonly ?string $at = null,
    ) {
    }

    public static function empty(): self
    {
        return new self();
    }

    /** @param array{cached_tracker_status?: mixed, cached_pr_state?: mixed, cached_agent_count?: mixed, cached_agent_activity?: mixed, cached_at?: mixed} $data */
    public static function fromJson(array $data): self
    {
        return new self(
            trackerStatus: isset($data['cached_tracker_status']) ? (string) $data['cached_tracker_status'] : null,
            prState: isset($data['cached_pr_state']) ? (string) $data['cached_pr_state'] : null,
            agentCount: isset($data['cached_agent_count']) ? (int) $data['cached_agent_count'] : null,
            agentActivity: isset($data['cached_agent_activity']) ? (string) $data['cached_agent_activity'] : null,
            at: isset($data['cached_at']) ? (string) $data['cached_at'] : null,
        );
    }

    /** @return array<string, string|int|null> */
    public function toJson(): array
    {
        return [
            'cached_tracker_status' => $this->trackerStatus,
            'cached_pr_state' => $this->prState,
            'cached_agent_count' => $this->agentCount,
            'cached_agent_activity' => $this->agentActivity,
            'cached_at' => $this->at,
        ];
    }
}
