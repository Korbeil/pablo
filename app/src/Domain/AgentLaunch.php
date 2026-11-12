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
 * $handle / $ade / $runtime say where the run lives once the detached
 * launch subprocess knows it (agent:list, agent:stop); null for a run
 * launched before they were recorded. $stopReason is set when PABLO ended
 * the run itself: 'stopped' (agent:stop) or 'timed-out' (agents.timeout_minutes).
 */
final class AgentLaunch
{
    public const STOPPED = 'stopped';
    public const TIMED_OUT = 'timed-out';

    public function __construct(
        public readonly Agent $agent,
        public readonly ?string $launchedAt,
        public readonly int $attempts,
        public readonly ?string $finishedAt = null,
        public readonly ?string $runId = null,
        public readonly bool $reported = false,
        public readonly ?string $handle = null,
        public readonly ?string $ade = null,
        public readonly ?string $runtime = null,
        public readonly ?string $stopReason = null,
    ) {
    }

    /** This launch, under analytics run id $runId. */
    public function withRunId(string $runId): self
    {
        return new self($this->agent, $this->launchedAt, $this->attempts, $this->finishedAt, $runId, $this->reported, $this->handle, $this->ade, $this->runtime, $this->stopReason);
    }

    /** This launch, now known to run as $handle in $ade with $runtime. */
    public function withHandle(string $handle, string $ade, string $runtime): self
    {
        return new self($this->agent, $this->launchedAt, $this->attempts, $this->finishedAt, $this->runId, $this->reported, $handle, $ade, $runtime, $this->stopReason);
    }

    /** This launch, ended by PABLO at $finishedAt (see $stopReason); still to report. */
    public function stopped(string $finishedAt, string $reason): self
    {
        return new self($this->agent, $this->launchedAt, $this->attempts, $finishedAt, $this->runId, $this->reported, $this->handle, $this->ade, $this->runtime, $reason);
    }

    /** This launch, concluded at $finishedAt and reported under $runId. */
    public function reported(string $finishedAt, string $runId): self
    {
        return new self($this->agent, $this->launchedAt, $this->attempts, $finishedAt, $runId, true, $this->handle, $this->ade, $this->runtime, $this->stopReason);
    }

    /** @param array{launched_at?: string|null, attempts?: int, finished_at?: string|null, run_id?: string|null, reported?: bool, handle?: string|null, ade?: string|null, runtime?: string|null, stop_reason?: string|null} $data */
    public static function fromJson(string $label, array $data): self
    {
        $string = static fn (mixed $v): ?string => \is_string($v) && '' !== $v ? $v : null;

        return new self(
            agent: Agent::tryByName($label) ?? Agent::TaskAnalyst,
            launchedAt: $data['launched_at'] ?? null,
            attempts: (int) ($data['attempts'] ?? 1),
            finishedAt: $data['finished_at'] ?? null,
            runId: $string($data['run_id'] ?? null),
            reported: (bool) ($data['reported'] ?? false),
            handle: $string($data['handle'] ?? null),
            ade: $string($data['ade'] ?? null),
            runtime: $string($data['runtime'] ?? null),
            stopReason: $string($data['stop_reason'] ?? null),
        );
    }

    /** @return array{launched_at: string|null, attempts: int, finished_at: string|null, run_id: string|null, reported: bool, handle?: string, ade?: string, runtime?: string, stop_reason?: string} */
    public function toJson(): array
    {
        $json = [
            'launched_at' => $this->launchedAt,
            'attempts' => $this->attempts,
            'finished_at' => $this->finishedAt,
            'run_id' => $this->runId,
            'reported' => $this->reported,
        ];
        // Only when known, so records of runs launched before stay unchanged.
        foreach (['handle' => $this->handle, 'ade' => $this->ade, 'runtime' => $this->runtime, 'stop_reason' => $this->stopReason] as $key => $value) {
            if (null !== $value) {
                $json[$key] = $value;
            }
        }

        return $json;
    }
}
