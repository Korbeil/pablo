<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Poller\Poller;
use Pablo\StateMachine\StateMachine;

/**
 * The agent-runner seam StateMachine/Poller depend on, so tests can inject a
 * fake instead of ever starting a real `opencode run` (an actual LLM call).
 * Terminal ids correspond to the fake's callables in `tests/FakeAgents.php`.
 */
interface AgentLauncherInterface
{
    public function launch(string $worktree, \Pablo\Domain\Agent $agent, string $prompt, string $project, string $branch): string;

    public function runStartupScript(string $worktree, string $script, string $project, string $branch): string;

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array;

    /** @param array<int, string> $worktrees @return array<string, array<int, SessionInfo>> */
    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array;

    /**
     * Sessions for display/rendering, counting a finished (orca "done") analyst
     * as waiting so the "💭 Waiting for feedback" split surfaces it.
     *
     * @return array<int, SessionInfo>
     */
    public function displaySessions(string $worktree): array;

    /**
     * Bulk variant of displaySessions().
     *
     * @param list<string> $worktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    public function bulkDisplaySessions(array $worktrees): array;

    public function waitForHandle(string $handle, int $timeoutS = 0): void;

    public function spawnWatcher(string $project, string $branch, string $handle, string $agent, string $then, ?string $expectState = null): void;

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void;

    public function refreshAgentDisplayCache(string $project, string $branch, string $worktree): void;

    public function launchHeadless(string $worktree, string $agent, string $prompt): string;

    public function hasAnyOrcaAgent(string $worktree): bool;
}
