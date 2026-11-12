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

    /**
     * Run the agent launch synchronously and return an ADE handle (e.g. a
     * terminal or session id, or "pid:N" for a headless run). Only ever runs inside
     * the detached internal:launch-agent subprocess. $model overrides the
     * agent file's frontmatter model (null = frontmatter fallback).
     */
    public function doLaunchAgent(string $worktree, string $agent, string $prompt, ?string $model = null): string;

    /** Run the startup script synchronously and return an ADE handle. */
    public function doRunStartupScript(string $worktree, string $script): string;

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array;

    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array;

    /**
     * Sessions for display/rendering, counting a finished analyst as waiting
     * so the "💭 Waiting for feedback" split surfaces it.
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

    /**
     * @param string|null $runId             analytics run id forwarded to the watcher (--run-id)
     * @param string|null $promptFingerprint sha256 prefix of the launch prompt for usage attribution
     */
    public function spawnWatcher(string $project, string $branch, string $handle, string $agent, string $then, ?string $expectState = null, ?string $runId = null, ?string $promptFingerprint = null): void;

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void;

    public function refreshAgentDisplayCache(string $project, string $branch, string $worktree): void;

    /**
     * $sessionId: a session id assigned up front; null lets the runtime pick
     * one (AgentRuntimeInterface::newSessionId(), or the runtime's own id when
     * that is null).
     */
    public function launchHeadless(string $worktree, string $agent, string $prompt, ?string $model = null, ?string $sessionId = null): string;

    /** Whether the ADE holds any agent for $worktree, finished ones included. */
    public function hasAnyAgent(string $worktree): bool;

    /**
     * Whether the run behind $handle (launched in $worktree) is still at
     * work. A finished run the ADE keeps open for the user (an interactive
     * session done with its prompt) is not.
     */
    public function isRunning(string $handle, string $worktree): bool;

    /**
     * Stop the run behind $handle: a headless one by its process group, an
     * ADE one through the ADE's CLI when it has a way to. Never throws.
     */
    public function stop(string $handle, string $worktree): AgentStop;

    /**
     * Live background runs across every worktree, read from their pidfiles.
     *
     * @return list<DetachedRun>
     */
    public function detachedRuns(): array;
}
