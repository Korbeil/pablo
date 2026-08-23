<?php

declare(strict_types=1);

namespace Pablo\Domain;

/**
 * One fire-and-forget agent-launch record (label -> {launched_at, attempts}).
 * Replaces the loose nested arrays the poller used for self-healing.
 *
 * $runId / $reported carry the analytics reconciliation state: reported
 * means an agent_run_finished event has been emitted for this launch by
 * either its watcher or the poller's sweep, so it must never be emitted
 * twice.
 */
final class AgentLaunch
{
    public function __construct(
        public readonly Agent $agent,
        public readonly ?string $launchedAt,
        public readonly int $attempts,
        public readonly ?string $finishedAt = null,
        public readonly ?string $runId = null,
        public readonly bool $reported = false,
    ) {
    }

    /** @param array{launched_at?: string|null, attempts?: int, finished_at?: string|null, run_id?: string|null, reported?: bool} $data */
    public static function fromJson(string $label, array $data): self
    {
        $rawRunId = $data['run_id'] ?? null;
        $runId = \is_string($rawRunId) && '' !== $rawRunId ? $rawRunId : null;

        return new self(
            agent: Agent::tryByName($label) ?? Agent::TaskAnalyst,
            launchedAt: $data['launched_at'] ?? null,
            attempts: (int) ($data['attempts'] ?? 1),
            finishedAt: $data['finished_at'] ?? null,
            runId: $runId,
            reported: (bool) ($data['reported'] ?? false),
        );
    }

    /** @return array{launched_at: string|null, attempts: int, finished_at: string|null, run_id: string|null, reported: bool} */
    public function toJson(): array
    {
        return [
            'launched_at' => $this->launchedAt,
            'attempts' => $this->attempts,
            'finished_at' => $this->finishedAt,
            'run_id' => $this->runId,
            'reported' => $this->reported,
        ];
    }
}
