<?php

declare(strict_types=1);

namespace Pablo\Listing;

use Pablo\Agents\SessionInfo;
use Pablo\Domain\Task;
use Pablo\Provider\Gh\PrInfo;

/**
 * What `pablo task:list` fetched in bulk before building its rows: agent
 * sessions, tracker statuses and PRs, only for the tasks whose display cache
 * cannot answer (or all of them with --live / --refresh).
 *
 * A null lookup means "not prefetched": the row falls back to the cache, or
 * to a single live call.
 */
final readonly class TaskPrefetch
{
    /**
     * @param array<string, list<SessionInfo>>     $sessionsByWorktree worktree path => sessions
     * @param array<string, array<string, string>> $trackerStatuses    provider => issue key => status
     * @param array<string, array<string, PrInfo>> $prsByRepo          repo slug => branch => PR
     */
    public function __construct(
        public array $sessionsByWorktree = [],
        public array $trackerStatuses = [],
        public array $prsByRepo = [],
    ) {
    }

    /** @return list<SessionInfo>|null */
    public function sessionsFor(Task $task): ?array
    {
        return $this->sessionsByWorktree[$task->worktreePath] ?? null;
    }

    /** @return array<string, string>|null */
    public function trackerStatusesFor(string $provider): ?array
    {
        return $this->trackerStatuses[$provider] ?? null;
    }

    /** @return array<string, PrInfo>|null */
    public function prsFor(string $slug): ?array
    {
        return $this->prsByRepo[$slug] ?? null;
    }
}
