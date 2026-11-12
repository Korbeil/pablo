<?php

declare(strict_types=1);

namespace Pablo\Agents;

/**
 * One PABLO-launched agent run still at work (agent:list, agent:stop).
 * $recorded: it has a launch record on its task (a run Sync started — the
 * rebase conflict resolver — has none, only its pidfile).
 */
final readonly class ActiveRun
{
    public function __construct(
        public string $project,
        public string $branch,
        public string $worktree,
        public string $agent,
        public string $ade,
        public ?string $runtime,
        public string $handle,
        public ?string $startedAt,
        public ?int $elapsedS,
        public ?string $resume,
        public bool $recorded,
    ) {
    }

    /** @return array{project: string, branch: string, worktree: string, agent: string, ade: string, runtime: ?string, handle: string, started_at: ?string, elapsed_s: ?int, resume: ?string, recorded: bool} */
    public function toJson(): array
    {
        return [
            'project' => $this->project,
            'branch' => $this->branch,
            'worktree' => $this->worktree,
            'agent' => $this->agent,
            'ade' => $this->ade,
            'runtime' => $this->runtime,
            'handle' => $this->handle,
            'started_at' => $this->startedAt,
            'elapsed_s' => $this->elapsedS,
            'resume' => $this->resume,
            'recorded' => $this->recorded,
        ];
    }
}
