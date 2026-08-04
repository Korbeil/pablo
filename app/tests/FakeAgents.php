<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\SessionInfo;

final class FakeAgents implements AgentLauncherInterface
{
    /** @var list<string> agent names launched */
    public array $launch = [];

    /** @var list<string> prompts for each launch */
    public array $launchPrompts = [];

    /** @var list<string> */
    public array $startupScript = [];

    public int $refreshCount = 0;

    /** @var array<int, SessionInfo> configurable active sessions */
    public array $active = [];

    /** @var array<string, array<int, SessionInfo>> configurable bulk sessions */
    public array $bulk = [];

    /** @var list<string> recorded display names */
    public array $displayNames = [];

    public ?string $workingTreeSessionsKey = null;

    public function launch(string $worktree, \Pablo\Domain\Agent $agent, string $prompt, string $project, string $branch): string
    {
        $this->launch[] = $agent->value;
        $this->launchPrompts[] = $prompt;

        return 'term_1';
    }

    public function runStartupScript(string $worktree, string $script, string $project, string $branch): string
    {
        $this->startupScript[] = $script;

        return 'term_2';
    }

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array
    {
        return $this->active;
    }

    /**
     * @param list<string> $worktrees
     *
     * @return array<string, array<int, SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array
    {
        $byWorktree = [];
        foreach ($worktrees as $wt) {
            $byWorktree[$wt] = $this->bulk[$wt] ?? [];
        }

        return $byWorktree;
    }

    public function waitForHandle(string $handle, int $timeoutS = 0): void
    {
    }

    public function spawnWatcher(string $project, string $branch, string $handle, string $then, ?string $expectState = null): void
    {
    }

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void
    {
        $this->displayNames[] = $name;
    }

    public function refreshAgentDisplayCache(string $project, string $branch, string $worktree): void
    {
        ++$this->refreshCount;
    }

    public function launchHeadless(string $worktree, string $agent, string $prompt): string
    {
        return 'pid:0';
    }
}
