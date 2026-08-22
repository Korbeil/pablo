<?php

declare(strict_types=1);

namespace Pablo\Poller;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\AgentActivity;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\PrBadge;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Store\TaskLockedException;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * Post-draft state polling and merge auto-close.
 */
final class Poller
{
    public const POLL_LOCK_TIMEOUT_S = 2;
    public const LAUNCH_WINDOW_S = 240;
    public const LAUNCH_MAX_ATTEMPTS = 3;

    public const POLL_CHECKS = [
        State::Draft->value => ['checkCiRed', 'checkCiGreen'],
        State::CiRed->value => ['checkCiGreen'],
        State::ReadyToReview->value => ['checkCiRed', 'checkReviews'],
        State::WaitingReview->value => ['checkCiRed', 'checkReviews'],
        State::NeedsTesting->value => ['checkFailureSignal'],
    ];

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly GitRepoInterface $git,
        private readonly ProviderRegistryInterface $providers,
        private readonly RepoSlug $repoSlug,
        private readonly StateMachine $stateMachine,
        private readonly Time $time,
    ) {
    }

    // ------------------------------------------------------------- checks ---

    /** @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle */
    public function checkCiRed(TaskCtx $ctx, string $slug, ?array $trackerStatuses = null): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if ($ctx->task->ciIgnored || null === $prNumber) {
            return null;
        }
        if ('red' === $this->gh->ciStatus($slug, $prNumber, $ctx->cfg->ciIgnoreChecks)) {
            return State::CiRed;
        }

        return null;
    }

    /** @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle */
    public function checkCiGreen(TaskCtx $ctx, string $slug, ?array $trackerStatuses = null): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        if ('green' === $this->gh->ciStatus($slug, $prNumber, $ctx->cfg->ciIgnoreChecks)) {
            return State::ReadyToReview;
        }

        return null;
    }

    /** @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle */
    public function checkReviews(TaskCtx $ctx, string $slug, ?array $trackerStatuses = null): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        $anchor = $this->gh->readyAnchor($slug, $prNumber);
        $prReviews = $this->gh->fetchReviews($slug, $prNumber);
        $verdict = $this->gh->evaluateReviews($prReviews->reviews, $anchor, $prReviews->author, $ctx->cfg->botWhitelist);
        if ('approved' === $verdict) {
            return State::NeedsTesting;
        }
        if ('changes' === $verdict) {
            return State::RequestChanges;
        }

        return null;
    }

    /** @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle */
    public function checkFailureSignal(TaskCtx $ctx, string $slug, ?array $trackerStatuses = null): ?State
    {
        $task = $ctx->task;
        if (null === $ctx->cfg->failureSignal || null === $task->needsTestingEnteredAt) {
            return null;
        }
        $baseline = new \DateTimeImmutable($task->needsTestingEnteredAt);
        $lastHandled = null !== $task->lastHandledSignalAt
            ? new \DateTimeImmutable($task->lastHandledSignalAt)
            : new \DateTimeImmutable('@0');
        $provider = $this->providers->get($ctx->cfg->provider);
        $events = array_values(array_filter(
            $provider->failureSignalEvents($task, $ctx->cfg),
            static fn (\DateTimeImmutable $stamp) => $stamp > $baseline && $stamp > $lastHandled,
        ));
        if ([] !== $events) {
            $max = max($events);
            $task->lastHandledSignalAt = $max->format('c');

            return State::TestingFailed;
        }

        // Observed-transition fallback (Jira): seeing the issue *enter* the
        // failure status between two polls counts as one event.
        if ($provider->supportsSignalViaStatus() && null !== $task->issue) {
            $current = null !== $trackerStatuses && isset($trackerStatuses[$task->issue->key])
                ? $trackerStatuses[$task->issue->key]
                : $provider->issueStatus($task->issue->key, $ctx->cfg);
            $previous = $task->lastSeenIssueStatus;
            $task->lastSeenIssueStatus = $current;
            $ctx->store->save($task);
            if ($current === $ctx->cfg->failureSignal && $previous !== $ctx->cfg->failureSignal) {
                $task->lastHandledSignalAt = $this->time->utcnow();

                return State::TestingFailed;
            }
        }

        return null;
    }

    // ----------------------------------------------------------- polling ----

    /** @param list<string> $events */
    private function close(TaskCtx $ctx, array &$events): void
    {
        try {
            $this->git->removeWorktree($ctx->cfg->repoPath, $ctx->task->worktreePath, $ctx->task->branch);
            $events[] = "{$ctx->task->branch}: PR merged → task closed, worktree removed";
        } catch (PabloError $e) {
            $events[] = "{$ctx->task->branch}: PR merged → task closed (worktree already gone: {$e->getMessage()})";
        }
        $ctx->store->delete($ctx->task->project, $ctx->task->branch);
    }

    /** @param list<string> $events */
    private function stampFinishedAgents(TaskCtx $ctx, array &$events): void
    {
        $task = $ctx->task;
        $now = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp();
        $changed = false;
        foreach ($task->agentLaunches as $label => $record) {
            if (null !== $record->finishedAt || null === $record->launchedAt) {
                continue;
            }
            if ($record->attempts < self::LAUNCH_MAX_ATTEMPTS) {
                continue; // still within the healing window; don't preempt relaunch
            }
            $ageS = $now - (new \DateTimeImmutable($record->launchedAt))->getTimestamp();
            if ($ageS < self::LAUNCH_WINDOW_S) {
                continue; // give the agent time to show up before declaring it done
            }
            // PABLO gave up retrying a PABLO-triggered agent, it launched a
            // while back and the task hasn't moved: its run has concluded and
            // the ball is with the user (review the plan, commit-and-pr).
            // PABLO-owned, so it never depends on Orca reporting the agent.
            $task->agentLaunches[$label] = new AgentLaunch(
                $record->agent,
                $record->launchedAt,
                $record->attempts,
                $this->time->utcnow(),
            );
            $events[] = "{$task->branch}: {$record->agent->value} finished → waiting for feedback";
            $changed = true;
        }
        if ($changed) {
            $ctx->store->save($task);
        }
    }

    /** @param list<string> $events */
    private function relaunchStuckAgents(TaskCtx $ctx, array &$events): void
    {
        $task = $ctx->task;
        try {
            $sessions = $ctx->agents->activeSessions($task->worktreePath);
        } catch (\Throwable) {
            $sessions = [];
        }
        if ([] !== $sessions) {
            return;
        }
        if ($ctx->agents->hasAnyOrcaAgent($task->worktreePath)) {
            return;
        }
        if (null !== $task->issue) {
            $ghIssue = 'github' === $ctx->cfg->provider ? $task->issue->key : null;
            $ctx->agents->setWorktreeDisplayName($task->worktreePath, $task->issue->key, $ghIssue);
        }
        $healed = false;
        foreach ($this->stateMachine->specsFor($ctx, $task->state) as $spec) {
            $record = $task->agentLaunches[$spec['label']->value] ?? null;
            if (null === $record || null === $record->launchedAt) {
                continue;
            }
            if (null !== $record->finishedAt) {
                continue; // already concluded; don't re-fire a done agent
            }
            $ageS = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->getTimestamp()
                - (new \DateTimeImmutable($record->launchedAt))->getTimestamp();
            if ($ageS < self::LAUNCH_WINDOW_S) {
                continue;
            }
            if ($record->attempts >= self::LAUNCH_MAX_ATTEMPTS) {
                continue;
            }
            [$fn, $args] = $spec['build']($ctx);
            $fn(...$args);
            $task->agentLaunches[$spec['label']->value] = new AgentLaunch(
                $spec['label'],
                $this->time->utcnow(),
                $record->attempts + 1,
            );
            $events[] = "{$task->branch}: {$spec['label']->value} re-launch #".($record->attempts + 1)
                ." (no session observed after {$ageS}s)";
            $healed = true;
        }
        if ($healed) {
            $ctx->store->save($task);
        }
    }

    /**
     * @param list<string>               $events
     * @param array<string, string>|null $trackerStatuses
     */
    private function pollTask(TaskCtx $ctx, array &$events, ?array $trackerStatuses = null): void
    {
        $task = $ctx->task;
        $slug = $this->repoSlug->for($ctx->cfg);

        try {
            $actualBranch = $this->git->git($task->worktreePath, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (PabloError) {
            $actualBranch = $task->branch;
        }

        // A task that entered draft via /commit-and-pr may not know its PR yet.
        if (null === $task->prNumber && !\in_array($task->state, [State::InProgress, State::Waiting], true)) {
            $pr = $this->gh->prForBranch($slug, $actualBranch);
            if (null !== $pr) {
                $task->prNumber = $pr->number;
                $ctx->store->save($task);
            }
        }

        if ($task->merged) {
            if ([] === $ctx->agents->activeSessions($task->worktreePath)) {
                $this->close($ctx, $events);
            }

            return;
        }

        if (null !== $task->prNumber && $this->gh->isMerged($slug, $task->prNumber)) {
            if ([] !== $ctx->agents->activeSessions($task->worktreePath)) {
                $task->merged = true;
                $ctx->store->save($task);
                $events[] = "{$task->branch}: PR merged, close deferred (agents still running)";
            } else {
                $this->close($ctx, $events);
            }

            return;
        }

        if (State::Waiting === $task->state) {
            return; // everything else is deferred while paused
        }

        if (\in_array($task->state, StateMachine::AGENT_LAUNCH_STATES, true)) {
            $this->stampFinishedAgents($ctx, $events);
            $this->relaunchStuckAgents($ctx, $events);
        }
        if (State::InProgress === $task->state) {
            return;
        }
        if (null === $task->prNumber) {
            return;
        }

        foreach (self::POLL_CHECKS[$task->state->value] ?? [] as $checkName) {
            $target = $this->{$checkName}($ctx, $slug, $trackerStatuses);
            if (null !== $target) {
                $this->stateMachine->enterState($ctx, $target);
                $events[] = "{$task->branch}: → {$ctx->task->state->value}";

                return;
            }
        }
    }

    /** @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle */
    public function refreshDisplayCache(TaskCtx $ctx, AgentLauncherInterface $agents, ?array $trackerStatuses = null): void
    {
        $task = $ctx->task;
        $cfg = $ctx->cfg;
        $slug = $this->repoSlug->for($cfg);

        $trackerStatus = $task->displayCache->trackerStatus;
        $prState = $task->displayCache->prState;
        $agentCount = $task->displayCache->agentCount;
        $agentActivity = $task->displayCache->agentActivity;

        if (null !== $task->issue) {
            $previous = $task->displayCache->trackerStatus;
            try {
                $trackerStatus = null !== $trackerStatuses && isset($trackerStatuses[$task->issue->key])
                    ? $trackerStatuses[$task->issue->key]
                    : $this->providers->get($cfg->provider)->issueStatus($task->issue->key, $cfg);
            } catch (PabloError) {
                // keep the last known value rather than blanking it
            }
            // A lookup that came back empty or unresolved ("?") means the CLI
            // failed — treat it like the error above and keep the last known
            // value so a transient acli hiccup never poisons the display cache.
            if ('' === $trackerStatus || '?' === $trackerStatus) {
                $trackerStatus = $previous;
            }
        }

        try {
            try {
                $actualBranch = $this->git->git($task->worktreePath, ['rev-parse', '--abbrev-ref', 'HEAD']);
            } catch (PabloError) {
                $actualBranch = $task->branch;
            }
            $pr = $this->gh->prForBranch($slug, $actualBranch);
            if (null !== $pr && null === $task->prNumber) {
                $task->prNumber = $pr->number;
            }
            $prState = PrBadge::fromTask($task, $pr)->render();
        } catch (PabloError) {
            if (null === $task->prNumber) {
                $prState = '-';
            }
        }

        try {
            $sessions = $agents->displaySessions($task->worktreePath);
            $activityVo = AgentActivity::fromSessions($sessions);
            [$count, $activity] = [$activityVo->total, $activityVo->render()];
            $agentCount = $count;
            $agentActivity = $activity;
        } catch (\Throwable) {
            // ignore
        }

        $task->displayCache = new DisplayCache(
            trackerStatus: $trackerStatus,
            prState: $prState,
            agentCount: $agentCount,
            agentActivity: $agentActivity,
            at: $this->time->utcnow(),
        );
        $ctx->store->save($task);
    }

    /**
     * Batch tracker status lookups for every open task's issue in this project,
     * so the whole poll cycle costs one parallel fetch per provider instead of
     * N sequential CLI calls (each up to JIRA_CALL_TIMEOUT_S). Falls back to a
     * per-task live fetch if the batch fails.
     *
     * @return array<string, string> key => status
     */
    private function fetchTrackerStatuses(ProjectConfig $cfg, Store $store): array
    {
        $issues = [];
        foreach ($store->allTasks($cfg->name) as $task) {
            if (null !== $task->issue) {
                $issues[$task->issue->key] = $cfg;
            }
        }
        if ([] === $issues) {
            return [];
        }
        try {
            return $this->providers->get($cfg->provider)->batchIssueStatus($issues);
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return array<int, string> */
    public function pollProject(ProjectConfig $cfg, Store $store, AgentLauncherInterface $agents): array
    {
        $events = [];
        $trackerStatuses = $this->fetchTrackerStatuses($cfg, $store);
        foreach ($store->allTasks($cfg->name) as $task) {
            try {
                $lock = $store->taskLock($cfg->name, $task->branch, self::POLL_LOCK_TIMEOUT_S);
                try {
                    $fresh = $store->get($cfg->name, $task->branch);
                    if (null === $fresh) {
                        continue;
                    }
                    $ctx = new TaskCtx(task: $fresh, cfg: $cfg, store: $store, agents: $agents);
                    try {
                        $this->pollTask($ctx, $events, $trackerStatuses);
                    } catch (\Throwable $e) {
                        $events[] = "{$task->branch}: poll check failed: {$e->getMessage()}";
                    }
                    try {
                        if (null !== $store->get($cfg->name, $task->branch)) {
                            $this->refreshDisplayCache($ctx, $agents, $trackerStatuses);
                        }
                    } catch (\Throwable $e) {
                        $events[] = "{$task->branch}: display cache refresh failed: {$e->getMessage()}";
                    }
                } finally {
                    $lock->release();
                }
            } catch (TaskLockedException $e) {
                $events[] = "{$task->branch}: skipped ({$e->getMessage()})";
            }
        }

        return $events;
    }
}
