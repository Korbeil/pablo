<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\PabloError;

/**
 * The core of `pablo task:attach`: adopt a worktree PABLO did not create
 * (user-run inside an existing checkout) and give it a task record.
 *
 * Unlike TaskStarter this never creates branches or worktrees: the branch
 * and the worktree already exist, so attaching is a pure record write —
 * state-machine enter with no on-enter trigger, no agent launch, no
 * display-name registration.
 */
final class TaskAttacher
{
    public function __construct(
        private readonly GitRepoInterface $git,
        private readonly StateMachine $stateMachine,
        private readonly AnalyticsInterface $analytics,
        private readonly Store $store,
        private readonly Config $projectsLoader,
        private readonly AgentLauncherInterface $agents,
    ) {
    }

    /** @return array<string, ProjectConfig> */
    public function projects(): array
    {
        return $this->projectsLoader->loadProjects();
    }

    /**
     * @param string      $cwd     the directory the command runs in (a git worktree)
     * @param State       $state   the state the attached task starts in
     * @param string|null $project optional project override when the worktree's
     *                             main repo cannot be matched automatically
     */
    public function attach(string $cwd, State $state, ?string $project): AttachResult
    {
        $toplevel = $this->toplevel($cwd);
        $mainRepo = $this->mainRepoPath($toplevel);
        $this->assertNotMainCheckout($toplevel, $mainRepo);
        $this->assertNotAttachedYet($toplevel);
        $cfg = $this->resolveProject($mainRepo, $project);
        $branch = $this->currentBranch($toplevel, $cfg);

        $existing = $this->store->get($cfg->name, $branch);
        if (null !== $existing) {
            throw new PabloError("task {$cfg->name}/{$branch} already exists — use task:state on it instead");
        }

        $this->createTask($cfg, $branch, $toplevel, $state);

        return new AttachResult(project: $cfg->name, branch: $branch, worktreePath: $toplevel, state: $state);
    }

    /**
     * The worktree root the user is running from; realpath normalizes the
     * remaining comparisons (project matching, already-attached check).
     */
    private function toplevel(string $cwd): string
    {
        try {
            $toplevel = $this->git->git($cwd, ['rev-parse', '--show-toplevel']);
        } catch (PabloError) {
            throw new PabloError("{$cwd} is not inside a git worktree, so it cannot be attached as a PABLO task");
        }

        return (string) realpath(trim($toplevel));
    }

    /**
     * Main checkout of the repo the worktree belongs to, via the common git
     * directory: a linked worktree stores a ".git" file pointer there.
     */
    private function mainRepoPath(string $toplevel): string
    {
        $commonDir = trim($this->git->git($toplevel, ['rev-parse', '--path-format=absolute', '--git-common-dir']));

        return (string) realpath(\dirname($commonDir));
    }

    private function assertNotMainCheckout(string $toplevel, string $mainRepo): void
    {
        if ($toplevel === $mainRepo) {
            throw new PabloError("{$toplevel} is the main checkout of its repo, not a worktree — PABLO tasks always own a dedicated worktree");
        }
    }

    private function assertNotAttachedYet(string $toplevel): void
    {
        try {
            $existing = $this->store->taskForWorktreePath($toplevel);
        } catch (PabloError) {
            return; // no task record matches: nothing attached here yet
        }
        throw new PabloError("worktree {$toplevel} is already attached as task {$existing->project}/{$existing->branch}".' — use task:state to change it instead');
    }

    /**
     * The project whose repo the worktree branches from. Matched on the main
     * checkout's realpath (worktrees_root layout is irrelevant: foreign
     * worktrees may live anywhere).
     */
    private function resolveProject(string $mainRepo, ?string $project): ProjectConfig
    {
        $projects = $this->projects();

        if (null !== $project && '' !== $project) {
            $cfg = $projects[$project] ?? null;
            if (null === $cfg) {
                throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
            }

            return $cfg;
        }

        foreach ($projects as $cfg) {
            if ($mainRepo === (string) realpath($cfg->repoPath)) {
                return $cfg;
            }
        }

        throw new PabloError("no configured project matches the repo behind {$mainRepo} — add one with pablo project:new, or pass --project <name>");
    }

    private function currentBranch(string $toplevel, ProjectConfig $cfg): string
    {
        $branch = trim($this->git->git($toplevel, ['rev-parse', '--abbrev-ref', 'HEAD']));
        if ('' === $branch || 'HEAD' === $branch) {
            throw new PabloError("{$toplevel} is in detached-HEAD state — checkout a branch before attaching it");
        }
        if ($branch === $cfg->primaryBranch) {
            throw new PabloError("branch {$branch} is the project's primary branch — PABLO tasks never run on it");
        }

        return $branch;
    }

    private function createTask(ProjectConfig $cfg, string $branch, string $worktreePath, State $state): void
    {
        $lock = $this->store->taskLock($cfg->name, $branch);
        try {
            $task = new Task(project: $cfg->name, branch: $branch, worktreePath: $worktreePath, state: State::InProgress);
            $ctx = new TaskCtx(task: $task, cfg: $cfg, store: $this->store, agents: $this->agents);
            // trigger: false — a pure adopt: none of the state's on-enter
            // side effects (analyst launch, PR flips, ...) may fire, the
            // work and whatever agent runs on it pre-date PABLO.
            $this->stateMachine->enterState($ctx, $state, trigger: false);
            // Same lifetime semantics as TaskStarter::createTask(): the task
            // enters PABLO exactly here, once.
            $this->analytics->taskOpened($task);
        } finally {
            $lock->release();
        }
    }
}
