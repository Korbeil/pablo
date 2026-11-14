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
 *
 * $sessionId: the runtime session the run happens in, when PABLO knows it
 * (YOLO mode assigns one up front so its follow-up runs continue it).
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
        public readonly ?string $sessionId = null,
    ) {
    }

    /** @param array{launched_at?: string|null, attempts?: int, finished_at?: string|null, run_id?: string|null, reported?: bool, session_id?: string|null} $data */
    public static function fromJson(string $label, array $data): self
    {
        $rawRunId = $data['run_id'] ?? null;
        $runId = \is_string($rawRunId) && '' !== $rawRunId ? $rawRunId : null;
        $rawSession = $data['session_id'] ?? null;

        return new self(
            agent: Agent::tryFrom($label) ?? Agent::TaskAnalyst,
            launchedAt: $data['launched_at'] ?? null,
            attempts: (int) ($data['attempts'] ?? 1),
            finishedAt: $data['finished_at'] ?? null,
            runId: $runId,
            reported: (bool) ($data['reported'] ?? false),
            sessionId: \is_string($rawSession) && '' !== $rawSession ? $rawSession : null,
        );
    }

    /** The same record with $changes applied (named like the constructor's parameters). */
    public function with(
        ?string $finishedAt = null,
        ?string $runId = null,
        ?bool $reported = null,
        ?string $sessionId = null,
    ): self {
        return new self(
            $this->agent,
            $this->launchedAt,
            $this->attempts,
            $finishedAt ?? $this->finishedAt,
            $runId ?? $this->runId,
            $reported ?? $this->reported,
            $sessionId ?? $this->sessionId,
        );
    }

    /** @return array{launched_at: string|null, attempts: int, finished_at: string|null, run_id: string|null, reported: bool, session_id?: string} */
    public function toJson(): array
    {
        $json = [
            'launched_at' => $this->launchedAt,
            'attempts' => $this->attempts,
            'finished_at' => $this->finishedAt,
            'run_id' => $this->runId,
            'reported' => $this->reported,
        ];
        // Only when known: records without one stay byte-for-byte as before.
        if (null !== $this->sessionId) {
            $json['session_id'] = $this->sessionId;
        }

        return $json;
    }
}
