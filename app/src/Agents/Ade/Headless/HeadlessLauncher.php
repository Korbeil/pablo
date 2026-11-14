<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Headless;

use Pablo\Agents\Ade\AbstractAgentLauncher;

/**
 * No ADE at all: every agent runs headless in the background (see
 * HeadlessRunner), its activity read back from the pidfiles. Nothing to
 * open the session in — the runtime's own resume command does that.
 */
final class HeadlessLauncher extends AbstractAgentLauncher
{
    protected function adeName(): string
    {
        return HeadlessAde::NAME;
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt, ?string $model = null, ?string $sessionId = null): string
    {
        return $this->headless->launch($worktree, $agent, $prompt, $model, $sessionId);
    }

    public function doRunStartupScript(string $worktree, string $script): string
    {
        return $this->headless->launchCommand($worktree, 'startup-script', 'bash '.escapeshellarg($script));
    }

    /** A headless run has no idle state: display = active. */
    protected function sessionsFor(array $worktrees, bool $display): array
    {
        return $this->headless->sessionsByWorktree();
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
