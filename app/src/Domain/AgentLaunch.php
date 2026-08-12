<?php

declare(strict_types=1);

namespace Pablo\Domain;

/**
 * One fire-and-forget agent-launch record (label -> {launched_at, attempts}).
 * Replaces the loose nested arrays the poller used for self-healing.
 */
final class AgentLaunch
{
    public function __construct(
        public readonly Agent $agent,
        public readonly ?string $launchedAt,
        public readonly int $attempts,
        public readonly ?string $finishedAt = null,
    ) {
    }

    /** @param array{launched_at?: string|null, attempts?: int, finished_at?: string|null} $data */
    public static function fromJson(string $label, array $data): self
    {
        return new self(
            agent: Agent::tryByName($label) ?? Agent::TaskAnalyst,
            launchedAt: $data['launched_at'] ?? null,
            attempts: (int) ($data['attempts'] ?? 1),
            finishedAt: $data['finished_at'] ?? null,
        );
    }

    /** @return array{launched_at: string|null, attempts: int, finished_at: string|null} */
    public function toJson(): array
    {
        return [
            'launched_at' => $this->launchedAt,
            'attempts' => $this->attempts,
            'finished_at' => $this->finishedAt,
        ];
    }
}
