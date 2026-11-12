<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

final class SyncReport
{
    /** @param array<int, string> $conflictFiles */
    public function __construct(
        public string $worktree,
        public string $branch,
        public string $action, // up-to-date | would-sync | synced | conflict | lease-failed | dirty
        public int $behind = 0,
        public int $ahead = 0,
        public array $conflictFiles = [],
        public string $detail = '',
        public string $agentHandle = '', // agent session handle (see RuntimeSessionsInterface::handle(), SessionRef) when the conflict agent launched
        public string $target = '', // ref the sync rebased/merged onto (e.g. origin/main); set on conflict
    ) {
    }

    /**
     * @param array<string, mixed> $data one persisted report entry
     */
    public static function fromArray(array $data): self
    {
        return new self(
            worktree: '',
            branch: (string) ($data['branch'] ?? '?'),
            action: (string) ($data['action'] ?? 'unknown'),
            behind: (int) ($data['behind'] ?? 0),
            ahead: (int) ($data['ahead'] ?? 0),
            conflictFiles: \is_array($data['conflict_files'] ?? null)
                ? array_values(array_map(strval(...), $data['conflict_files']))
                : [],
            detail: (string) ($data['detail'] ?? ''),
            agentHandle: (string) ($data['agent_handle'] ?? ''),
        );
    }

    /**
     * The machine-readable form (`sync:run|sync:log --format=json`). Not the
     * persisted log entry: RebaseLog::toArray() owns that format.
     *
     * @return array{branch: string, worktree: string, action: string, behind: int, ahead: int, conflict_files: list<string>, detail: string, agent_handle: string, target: string}
     */
    public function toArray(): array
    {
        return [
            'branch' => $this->branch,
            'worktree' => $this->worktree,
            'action' => $this->action,
            'behind' => $this->behind,
            'ahead' => $this->ahead,
            'conflict_files' => array_values($this->conflictFiles),
            'detail' => $this->detail,
            'agent_handle' => $this->agentHandle,
            'target' => $this->target,
        ];
    }
}
