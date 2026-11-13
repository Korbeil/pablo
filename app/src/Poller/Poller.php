<?php

declare(strict_types=1);

namespace Pablo\Poller;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AgentRunRecord;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentActivity;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\PrBadge;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Hooks\Hooks;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Gh\ReviewVerdict;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Store\TaskLockedException;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * Post-draft state polling, merge auto-close and the closed-PR park.
 */
final class Poller
{
    public const POLL_LOCK_TIMEOUT_S = 2;
    public const LAUNCH_WINDOW_S = 240;
    public const LAUNCH_MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly GitRepoInterface $git,
        private readonly ProviderRegistryInterface $providers,
        private readonly RepoSlug $repoSlug,
        private readonly StateMachine $stateMachine,
        private readonly Time $time,
        private readonly UsageHarvesterInterface $usage,
        private readonly Hooks $hooks,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
    }

    // ------------------------------------------------------------- checks ---

    /**
     * The checks polled in $state, in order: the first one returning a
     * state moves the task there.
     *
     * @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle
     *
     * @return list<\Closure(TaskCtx, string): ?State>
     */
    private function checksFor(State $state, ?array $trackerStatuses): array
    {
        return match ($state) {
            State::Draft => [$this->checkCiRed(...), $this->checkCiGreen(...)],
            State::CiRed => [$this->checkCiGreen(...)],
            State::ReadyToReview, State::WaitingReview => [$this->checkCiRed(...), $this->checkReviews(...)],
            State::Approved => [$this->checkCiRed(...)],
            State::NeedsTesting => [fn (TaskCtx $ctx): ?State => $this->checkFailureSignal($ctx, $trackerStatuses)],
            State::RequestChanges => [$this->checkFeedbackAddressed(...)],
            default => [],
        };
    }

    private function checkCiRed(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if ($ctx->task->ciIgnored || null === $prNumber) {
            return null;
        }
        if ('red' === $this->gh->ciStatus($slug, $prNumber, $ctx->cfg->ci->ignoreChecks)) {
            return State::CiRed;
        }

        return null;
    }

    private function checkCiGreen(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        if ('green' === $this->gh->ciStatus($slug, $prNumber, $ctx->cfg->ci->ignoreChecks)) {
            return State::ReadyToReview;
        }

        return null;
    }

    private function checkReviews(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        $verdict = $this->gh->reviewVerdict($slug, $prNumber, $ctx->cfg->review->botWhitelist);
        if (ReviewVerdict::APPROVED === $verdict) {
            return State::Approved;
        }
        if (ReviewVerdict::CHANGES === $verdict) {
            return State::RequestChanges;
        }

        return null;
    }

    /**
     * The request-changes self-heal exit: a comment review whose threads were
     * all resolved is feedback addressed, so once pr-feedback has finished
     * and no unresolved signal remains, the pull request goes back to review
     * (ready-to-review marks it ready on GitHub, resetting the review anchor).
     * Deliberately NOT the /pablo-commit-and-pr path: nothing is committed or
     * pushed here, the git work stays whatever the worktree holds.
     */
    private function checkFeedbackAddressed(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        $record = $ctx->task->agentLaunches[Agent::PrFeedback->value] ?? null;
        if (null === $record || null === $record->finishedAt) {
            return null; // pr-feedback still running or never launched
        }
        $verdict = $this->gh->reviewVerdict($slug, $prNumber, $ctx->cfg->review->botWhitelist);
        if (ReviewVerdict::CHANGES === $verdict) {
            return null; // an unresolved signal still stands
        }

        return State::ReadyToReview;
    }

    /** @param array<string, string>|null $trackerStatuses key => status snapshot for this poll cycle */
    private function checkFailureSignal(TaskCtx $ctx, ?array $trackerStatuses): ?State
    {
        $task = $ctx->task;
        if (null === $ctx->cfg->testing->failureSignal || null === $task->needsTestingEnteredAt) {
            return null;
        }
        $baseline = new \DateTimeImmutable($task->needsTestingEnteredAt);
        $lastHandled = null !== $task->lastHandledSignalAt
            ? new \DateTimeImmutable($task->lastHandledSignalAt)
            : new \DateTimeImmutable('@0');
        $provider = $this->providers->get($ctx->cfg->tracker->provider);
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
            if ($current === $ctx->cfg->testing->failureSignal && $previous !== $ctx->cfg->testing->failureSignal) {
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
            $this->git->removeWorktree($ctx->cfg->repo->path, $ctx->task->worktreePath, $ctx->task->branch);
            $events[] = "{$ctx->task->branch}: PR merged → task closed, worktree removed";
        } catch (PabloError $e) {
            $events[] = "{$ctx->task->branch}: PR merged → task closed (worktree already gone: {$e->getMessage()})";
        }
        // Before the record disappears: the close event is what preserves the
        // task's final metadata for analytics (merge auto-close path).
        $this->analytics->taskClosed($ctx->task);
        $ctx->store->delete($ctx->task->project, $ctx->task->branch);
    }

    /**
     * Analytics reconciliation: every concluded PABLO-triggered agent run
     * must appear in the event log exactly once, whichever component
     * observed the conclusion. The watcher normally reports and marks the
     * launch reported=true; this sweep catches everything else (healed
     * launches, watchers that died before reporting, pre-analytics legacy).
     *
     * @param list<string> $events
     */
    private function stampFinishedAgents(TaskCtx $ctx, array &$events): void
    {
        $task = $ctx->task;
        $changed = false;
        $finished = [];
        foreach ($task->agentLaunches as $label => $record) {
            if ($record->reported || null === $record->launchedAt) {
                continue;
            }
            $finishedAt = $record->finishedAt;
            if (null === $finishedAt) {
                if ($record->attempts < self::LAUNCH_MAX_ATTEMPTS) {
                    continue; // still within the healing window; don't preempt relaunch
                }
                if ($this->ageS($record->launchedAt) < self::LAUNCH_WINDOW_S) {
                    continue; // give the agent time to show up before declaring it done
                }
                // PABLO gave up retrying a PABLO-triggered agent, it launched a
                // while back and the task hasn't moved: its run has concluded and
                // the ball is with the user (review the plan, commit-and-pr).
                // PABLO-owned, so it never depends on the ADE reporting the agent.
                $finishedAt = $this->time->utcnow();
                $events[] = "{$task->branch}: {$record->agent->value} finished → waiting for feedback";
                $finished[] = $record->agent;
            }
            $runId = $record->runId ?? bin2hex(random_bytes(8));
            try {
                $this->analytics->agentRunFinished(new AgentRunRecord(
                    project: $task->project,
                    branch: $task->branch,
                    runId: $runId,
                    agent: $record->agent->value,
                    backend: 'unknown',
                    startedAt: $record->launchedAt,
                    finishedAt: $finishedAt,
                    durationS: max(0, $this->time->parseTs($finishedAt)->getTimestamp() - $this->time->parseTs($record->launchedAt)->getTimestamp()),
                    usage: $this->usage->harvest($task->worktreePath, $record->launchedAt, null),
                ));
            } catch (\Throwable) {
                // analytics is best-effort by contract; still mark reported so
                // a poison run cannot re-harvest every cycle.
            }
            $task->agentLaunches[$label] = new AgentLaunch(
                $record->agent,
                $record->launchedAt,
                $record->attempts,
                $finishedAt,
                runId: $runId,
                reported: true,
            );
            $changed = true;
        }
        if ($changed) {
            $ctx->store->save($task);
        }
        // Only after the save, and only for runs this sweep declared finished
        // (the watcher fires its own): the user's hook, never blocking.
        foreach ($finished as $agent) {
            $this->hooks->agentFinished($ctx->cfg, $task, $agent);
        }
    }

    /** Seconds since $launchedAt. */
    private function ageS(string $launchedAt): int
    {
        return $this->time->parseTs($this->time->utcnow())->getTimestamp() - $this->time->parseTs($launchedAt)->getTimestamp();
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
        if ($ctx->agents->hasAnyAgent($task->worktreePath)) {
            return;
        }
        if (null !== $task->issue) {
            $ghIssue = 'github' === $ctx->cfg->tracker->provider ? $task->issue->key : null;
            $ctx->agents->setWorktreeDisplayName($task->worktreePath, $task->issue->key, $ghIssue);
        }
        $healed = false;
        foreach ($this->stateMachine->agentsFor($task->state) as $agent) {
            $record = $task->agentLaunches[$agent->value] ?? null;
            if (null === $record || null === $record->launchedAt) {
                continue;
            }
            if (null !== $record->finishedAt) {
                continue; // already concluded; don't re-fire a done agent
            }
            $ageS = $this->ageS($record->launchedAt);
            if ($ageS < self::LAUNCH_WINDOW_S || $record->attempts >= self::LAUNCH_MAX_ATTEMPTS) {
                continue;
            }
            if (!$this->stateMachine->launch($ctx, $agent, $record->attempts + 1)) {
                continue;
            }
            $events[] = "{$task->branch}: {$agent->value} re-launch #".($record->attempts + 1)
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

        // A task that entered draft via /pablo-commit-and-pr may not know its PR yet.
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

        $prState = null !== $task->prNumber ? $this->gh->prState($slug, $task->prNumber) : null;

        if ('MERGED' === $prState) {
            // Set before branching: the close event below is what analytics
            // records, and the flag must be true on the immediate path too.
            $task->merged = true;
            if ([] !== $ctx->agents->activeSessions($task->worktreePath)) {
                $ctx->store->save($task);
                $events[] = "{$task->branch}: PR merged, close deferred (agents still running)";
            } else {
                $this->close($ctx, $events);
            }

            return;
        }

        // Closed without merging: nothing is removed automatically. The task
        // parks in `closed`, worktree kept, until the user closes it
        // (task:close or the dashboard); a reopen sends it back to draft.
        if ('CLOSED' === $prState) {
            if (State::Closed !== $task->state) {
                $this->stateMachine->enterState($ctx, State::Closed);
                $events[] = "{$task->branch}: PR closed → closed (worktree kept until you close the task)";
            }

            return;
        }
        if (State::Closed === $task->state) {
            if ('OPEN' === $prState) {
                $this->stateMachine->enterState($ctx, State::Draft);
                $events[] = "{$task->branch}: PR reopened → draft";
            }

            return;
        }

        if (State::Waiting === $task->state) {
            return; // everything else is deferred while paused
        }

        if ([] !== $this->stateMachine->agentsFor($task->state)) {
            $this->stampFinishedAgents($ctx, $events);
            $this->relaunchStuckAgents($ctx, $events);
        }
        if (State::InProgress === $task->state) {
            return;
        }
        if (null === $task->prNumber) {
            return;
        }

        foreach ($this->checksFor($task->state, $trackerStatuses) as $check) {
            $target = $check($ctx, $slug);
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
                    : $this->providers->get($cfg->tracker->provider)->issueStatus($task->issue->key, $cfg);
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
            // Best-effort display column: an ADE CLI failing (or missing) must not fail the poll.
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
     * N sequential CLI calls (each up to the tracker's call timeout). Falls back to a
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
            return $this->providers->get($cfg->tracker->provider)->batchIssueStatus($issues);
        } catch (\Throwable) {
            return [];
        }
    }

    /** Per-task checks that failed during the last pollProject() call. */
    private int $lastCheckFailures = 0;

    public function lastCheckFailures(): int
    {
        return $this->lastCheckFailures;
    }

    /** @return array<int, string> */
    public function pollProject(ProjectConfig $cfg, Store $store, AgentLauncherInterface $agents): array
    {
        $events = [];
        $this->lastCheckFailures = 0;
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
                        ++$this->lastCheckFailures;
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
