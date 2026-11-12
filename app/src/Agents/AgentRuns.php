<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;

/**
 * The PABLO-launched agent runs still at work, and stopping them
 * (agent:list, agent:stop, the watcher's timeout).
 *
 * Built from two sources, never from a forge: the tasks' launch records
 * (the handle, ADE and runtime the detached launch recorded, liveness asked
 * from that ADE) and the background runs' pidfiles — which also catch the
 * runs without a record (the rebase conflict resolver Sync starts, a
 * startup script, a run launched before handles were recorded). Only runs
 * on a PABLO task's worktree are listed or stopped.
 */
final class AgentRuns
{
    /** @var array<string, AgentLauncherInterface> by ADE name ('' = the configured one) */
    private array $launchers = [];

    /**
     * @param (\Closure(?string): AgentLauncherInterface)|null $launcherFor the launcher of an ADE
     *                                                                      (null = the configured one); defaults to the factory
     */
    public function __construct(
        private readonly Store $store,
        private readonly AgentLauncherFactory $factory,
        private readonly GitRepoInterface $git,
        private readonly Time $time,
        private readonly ?\Closure $launcherFor = null,
    ) {
    }

    /**
     * Active runs, by project, branch and start.
     *
     * @return list<ActiveRun>
     */
    public function active(?string $project = null): array
    {
        $tasks = $this->store->allTasks($project);
        $byWorktree = [];
        foreach ($tasks as $task) {
            $byWorktree[$task->worktreePath] = $task;
        }
        $detached = [];
        foreach ($this->detachedRuns() as $run) {
            $detached[$run->handle] = $run;
        }

        $runs = [];
        $seen = [];
        foreach ($tasks as $task) {
            foreach ($task->agentLaunches as $label => $launch) {
                if (null !== $launch->finishedAt || null === $launch->handle) {
                    continue;
                }
                $pidfile = $detached[$launch->handle] ?? null;
                if (null === $pidfile && !$this->isRunning($launch->ade, $launch->handle, $task->worktreePath)) {
                    continue;
                }
                $seen[$launch->handle] = true;
                $runs[] = $this->row(
                    $task,
                    (string) $label,
                    $pidfile->ade ?? $launch->ade ?? $this->factory->resolveBackend(),
                    $pidfile->runtime ?? $launch->runtime,
                    $launch->handle,
                    $launch->launchedAt ?? $pidfile?->startedAt,
                    $pidfile,
                    true,
                );
            }
        }
        foreach ($detached as $handle => $run) {
            $task = $byWorktree[$run->worktree] ?? null;
            if (isset($seen[$handle]) || null === $task) {
                continue;
            }
            $runs[] = $this->row($task, $run->label, $run->ade, $run->runtime, $handle, $run->startedAt, $run, isset($task->agentLaunches[$run->label]));
        }
        usort($runs, static fn (ActiveRun $a, ActiveRun $b): int => [$a->project, $a->branch, (string) $a->startedAt] <=> [$b->project, $b->branch, (string) $b->startedAt]);

        return $runs;
    }

    /**
     * Stop $task's active runs ($agent's only, when given) and mark their
     * launch records finished with $reason (AgentLaunch::STOPPED or
     * TIMED_OUT).
     *
     * @return list<StopReport>
     */
    public function stop(Task $task, ?string $agent = null, string $reason = AgentLaunch::STOPPED): array
    {
        $reports = [];
        foreach ($this->active($task->project) as $run) {
            if ($run->branch !== $task->branch || (null !== $agent && $run->agent !== $agent)) {
                continue;
            }
            $reports[] = $this->stopRun($task->project, $task->branch, $run->worktree, $run->agent, $run->handle, $run->ade, $reason);
        }

        return $reports;
    }

    /**
     * Stop one run, mark its launch record finished with $reason, and leave
     * the worktree clean when it was the conflict resolver mid-rebase.
     */
    public function stopRun(string $project, string $branch, string $worktree, string $agent, string $handle, ?string $ade, string $reason): StopReport
    {
        $outcome = $this->launcher($ade)->stop($handle, $worktree);
        if (!$outcome->stopped) {
            return new StopReport($agent, $handle, $outcome);
        }
        $this->markStopped($project, $branch, $agent, $reason);
        $cleanup = Agent::RebaseConflictResolver->value === $agent ? $this->abortInProgress($worktree) : null;

        return new StopReport($agent, $handle, $outcome, $cleanup);
    }

    /** @return list<DetachedRun> */
    private function detachedRuns(): array
    {
        try {
            return $this->launcher(null)->detachedRuns();
        } catch (PabloError) {
            return [];
        }
    }

    private function isRunning(?string $ade, string $handle, string $worktree): bool
    {
        try {
            return $this->launcher($ade)->isRunning($handle, $worktree);
        } catch (\Throwable) {
            return false;
        }
    }

    /** The launcher of $ade, the configured one when null or unusable. */
    public function launcher(?string $ade): AgentLauncherInterface
    {
        $key = $ade ?? '';
        if (isset($this->launchers[$key])) {
            return $this->launchers[$key];
        }
        if (null !== $this->launcherFor) {
            return $this->launchers[$key] = ($this->launcherFor)($ade);
        }
        try {
            $launcher = $this->factory->create($ade);
        } catch (PabloError $e) {
            if (null === $ade) {
                throw $e;
            }
            // An ADE no longer usable with the configured runtime: background
            // handles still stop through any launcher, and the other ADE's
            // handles are refused by it with a clear outcome.
            $launcher = $this->launcher(null);
        }

        return $this->launchers[$key] = $launcher;
    }

    private function row(Task $task, string $agent, string $ade, ?string $runtime, string $handle, ?string $startedAt, ?DetachedRun $pidfile, bool $recorded): ActiveRun
    {
        $elapsed = null;
        if (null !== $startedAt) {
            try {
                $elapsed = max(0, $this->time->parseTs($this->time->utcnow())->getTimestamp() - $this->time->parseTs($startedAt)->getTimestamp());
            } catch (\Throwable) {
                $elapsed = null;
            }
        }

        return new ActiveRun(
            project: $task->project,
            branch: $task->branch,
            worktree: $task->worktreePath,
            agent: $agent,
            ade: $ade,
            runtime: $runtime,
            handle: $handle,
            startedAt: $startedAt,
            elapsedS: $elapsed,
            resume: $this->resumeCommand($pidfile),
            recorded: $recorded,
        );
    }

    /** How to resume a background run's session, when its pidfile names one. */
    private function resumeCommand(?DetachedRun $run): ?string
    {
        if (null === $run || null === $run->runtime || null === $run->session) {
            return null;
        }
        try {
            $sessions = $this->factory->runtimes()->resolve($run->runtime)->sessions();

            return $sessions->resumeCommand($sessions->handle($run->session));
        } catch (\Throwable) {
            return null;
        }
    }

    private function markStopped(string $project, string $branch, string $agent, string $reason): void
    {
        $label = Agent::tryByName($agent);
        if (null === $label) {
            return;
        }
        $lock = $this->store->taskLock($project, $branch);
        try {
            $task = $this->store->get($project, $branch);
            $launch = $task?->agentLaunches[$label->value] ?? null;
            if (null === $task || null === $launch || null !== $launch->finishedAt) {
                return;
            }
            // Not reported yet: the watcher (waking up as the run ends) or the
            // poller's sweep reports it once, keeping this stop time.
            $task->agentLaunches[$label->value] = $launch->stopped($this->time->utcnow(), $reason);
            $this->store->save($task);
        } finally {
            $lock->release();
        }
    }

    /**
     * A conflict resolver stopped mid-rebase (or mid-merge) would leave the
     * worktree in that state: abort it, as Sync does when it hands over.
     */
    private function abortInProgress(string $worktree): ?string
    {
        try {
            $gitDir = $this->git->git($worktree, ['rev-parse', '--absolute-git-dir'], check: false);
            if ('' === $gitDir) {
                return null;
            }
            $op = match (true) {
                is_dir($gitDir.'/rebase-merge') || is_dir($gitDir.'/rebase-apply') => 'rebase',
                is_file($gitDir.'/MERGE_HEAD') => 'merge',
                default => null,
            };
            if (null === $op) {
                return null;
            }
            $this->git->git($worktree, [$op, '--abort']);

            return "aborted the {$op} in progress in {$worktree}";
        } catch (\Throwable $e) {
            return "could not abort the operation in progress in {$worktree}: ".$e->getMessage();
        }
    }
}
