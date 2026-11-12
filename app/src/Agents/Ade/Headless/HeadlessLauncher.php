<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Headless;

use Pablo\Agents\Ade\AbstractAgentLauncher;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Support\ProcessRunnerInterface;

/**
 * No ADE at all: every agent runs headless in the background (see
 * HeadlessRunner), its activity read back from the pidfiles. Nothing to
 * open the session in — the runtime's own resume command does that.
 */
final class HeadlessLauncher extends AbstractAgentLauncher
{
    public function __construct(
        ProcessRunnerInterface $runner,
        Time $time = new Time(),
        ?Store $store = null,
        ?string $shimPath = null,
        ?AgentRuntimeInterface $runtime = null,
    ) {
        parent::__construct(HeadlessAde::NAME, $runner, $time, $store, $shimPath, $runtime);
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt, ?string $model = null): string
    {
        return $this->headless->launch($worktree, $agent, $prompt, $model);
    }

    public function doRunStartupScript(string $worktree, string $script): string
    {
        return $this->headless->launchCommand($worktree, 'startup-script', 'bash '.escapeshellarg($script));
    }

    public function activeSessions(string $worktree): array
    {
        return $this->headless->sessionsByWorktree()[$worktree] ?? [];
    }

    public function bulkActiveSessions(array $worktrees): array
    {
        $grouped = $this->headless->sessionsByWorktree();
        $out = [];
        foreach ($worktrees as $worktree) {
            $out[$worktree] = $grouped[$worktree] ?? [];
        }

        return $out;
    }

    /** A headless run has no idle state: display = active. */
    public function displaySessions(string $worktree): array
    {
        return $this->activeSessions($worktree);
    }

    public function bulkDisplaySessions(array $worktrees): array
    {
        return $this->bulkActiveSessions($worktrees);
    }

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void
    {
        // No workspace to name.
    }

    public function hasAnyAgent(string $worktree): bool
    {
        return [] !== $this->activeSessions($worktree);
    }

    protected function waitForAdeHandle(string $handle, int $timeoutS): void
    {
        // Every handle here is a headless one.
    }
}
