<?php

declare(strict_types=1);

namespace Pablo\Poller;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Listing\Listing;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\Jira;
use Pablo\Provider\Tracker\ProviderRegistry;
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

    // ------------------------------------------------------------- checks ---

    public static function checkCiRed(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if ($ctx->task->ciIgnored || null === $prNumber) {
            return null;
        }
        if ('red' === GhPr::ciStatus($slug, $prNumber, $ctx->cfg->ciIgnoreChecks)) {
            return State::CiRed;
        }

        return null;
    }

    public static function checkCiGreen(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        if ('green' === GhPr::ciStatus($slug, $prNumber, $ctx->cfg->ciIgnoreChecks)) {
            return State::ReadyToReview;
        }

        return null;
    }

    public static function checkReviews(TaskCtx $ctx, string $slug): ?State
    {
        $prNumber = $ctx->task->prNumber;
        if (null === $prNumber) {
            return null;
        }
        $anchor = GhPr::readyAnchor($slug, $prNumber);
        [$author, $reviews] = GhPr::fetchReviews($slug, $prNumber);
        $verdict = GhPr::evaluateReviews($reviews, $anchor, $author, $ctx->cfg->botWhitelist);
        if ('approved' === $verdict) {
            return State::NeedsTesting;
        }
        if ('changes' === $verdict) {
            return State::RequestChanges;
        }

        return null;
    }

    public static function checkFailureSignal(TaskCtx $ctx, string $slug): ?State
    {
        $task = $ctx->task;
        if (null === $ctx->cfg->failureSignal || null === $task->needsTestingEnteredAt) {
            return null;
        }
        $baseline = new \DateTimeImmutable($task->needsTestingEnteredAt);
        $lastHandled = null !== $task->lastHandledSignalAt
            ? new \DateTimeImmutable($task->lastHandledSignalAt)
            : new \DateTimeImmutable('@0');
        $provider = ProviderRegistry::get($ctx->cfg->provider);
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
            $current = $provider->issueStatus($task->issue->key, $ctx->cfg);
            $previous = $task->lastSeenIssueStatus;
            $task->lastSeenIssueStatus = $current;
            $ctx->store->save($task);
            if ($current === $ctx->cfg->failureSignal && $previous !== $ctx->cfg->failureSignal) {
                $task->lastHandledSignalAt = Time::utcnow();

                return State::TestingFailed;
            }
        }

        return null;
    }

    // ----------------------------------------------------------- polling ----

    /** @param list<string> $events */
    private static function close(TaskCtx $ctx, array &$events): void
    {
        GitRepo::removeWorktree($ctx->cfg->repoPath, $ctx->task->worktreePath, $ctx->task->branch);
        $ctx->store->delete($ctx->task->project, $ctx->task->branch);
        $events[] = "{$ctx->task->branch}: PR merged → task closed, worktree removed";
    }

    /** @param list<string> $events */
    private static function relaunchStuckAgents(TaskCtx $ctx, array &$events): void
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
        if (null !== $task->issue) {
            $ghIssue = 'github' === $ctx->cfg->provider ? $task->issue->key : null;
            $ctx->agents->setWorktreeDisplayName($task->worktreePath, $task->issue->key, $ghIssue);
        }
        $healed = false;
        foreach (StateMachine::specsFor($ctx, $task->state) as $spec) {
            $record = $task->agentLaunches[$spec['label']->value] ?? null;
            if (null === $record || null === $record->launchedAt) {
                continue;
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
                Time::utcnow(),
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

    /** @param list<string> $events */
    private static function pollTask(TaskCtx $ctx, array &$events): void
    {
        $task = $ctx->task;
        $slug = RepoSlug::for($ctx->cfg);

        try {
            $actualBranch = GitRepo::git($task->worktreePath, ['rev-parse', '--abbrev-ref', 'HEAD']);
        } catch (PabloError) {
            $actualBranch = $task->branch;
        }

        // A task that entered draft via /commit-and-pr may not know its PR yet.
        if (null === $task->prNumber && !\in_array($task->state, [State::InProgress, State::Waiting], true)) {
            $pr = GhPr::prForBranch($slug, $actualBranch);
            if (null !== $pr) {
                $task->prNumber = $pr->number;
                $ctx->store->save($task);
            }
        }

        if ($task->merged) {
            if ([] === $ctx->agents->activeSessions($task->worktreePath)) {
                self::close($ctx, $events);
            }

            return;
        }

        if (null !== $task->prNumber && GhPr::isMerged($slug, $task->prNumber)) {
            if ([] !== $ctx->agents->activeSessions($task->worktreePath)) {
                $task->merged = true;
                $ctx->store->save($task);
                $events[] = "{$task->branch}: PR merged, close deferred (agents still running)";
            } else {
                self::close($ctx, $events);
            }

            return;
        }

        if (State::Waiting === $task->state) {
            return; // everything else is deferred while paused
        }

        if (\in_array($task->state, StateMachine::AGENT_LAUNCH_STATES, true)) {
            self::relaunchStuckAgents($ctx, $events);
        }
        if (State::InProgress === $task->state) {
            return;
        }
        if (null === $task->prNumber) {
            return;
        }

        foreach (self::POLL_CHECKS[$task->state->value] ?? [] as $checkName) {
            $target = self::{$checkName}($ctx, $slug);
            if (null !== $target) {
                StateMachine::enterState($ctx, $target);
                $events[] = "{$task->branch}: → {$ctx->task->state->value}";

                return;
            }
        }
    }

    public static function refreshDisplayCache(TaskCtx $ctx, AgentLauncherInterface $agents): void
    {
        $task = $ctx->task;
        $cfg = $ctx->cfg;
        $slug = RepoSlug::for($cfg);

        $trackerStatus = $task->displayCache->trackerStatus;
        $prState = $task->displayCache->prState;
        $agentCount = $task->displayCache->agentCount;
        $agentActivity = $task->displayCache->agentActivity;

        if (null !== $task->issue) {
            try {
                $trackerStatus = ProviderRegistry::get($cfg->provider)->issueStatus($task->issue->key, $cfg);
            } catch (PabloError) {
                // keep the last known value rather than blanking it
            }
        }

        try {
            try {
                $actualBranch = GitRepo::git($task->worktreePath, ['rev-parse', '--abbrev-ref', 'HEAD']);
            } catch (PabloError) {
                $actualBranch = $task->branch;
            }
            $pr = GhPr::prForBranch($slug, $actualBranch);
            if (null !== $pr && null === $task->prNumber) {
                $task->prNumber = $pr->number;
            }
            $prState = Listing::prStateCell($task, $pr);
        } catch (PabloError) {
            if (null === $task->prNumber) {
                $prState = '-';
            }
        }

        try {
            $sessions = $agents->activeSessions($task->worktreePath);
            [$count, $activity] = Listing::agentActivitySummary($sessions);
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
            at: Time::utcnow(),
        );
        $ctx->store->save($task);
    }

    /** @return array<int, string> */
    public static function pollProject(ProjectConfig $cfg, Store $store, AgentLauncherInterface $agents): array
    {
        $events = [];
        foreach ($store->allTasks($cfg->name) as $task) {
            try {
                $lock = Store::taskLock($store, $cfg->name, $task->branch, self::POLL_LOCK_TIMEOUT_S);
                try {
                    $fresh = $store->get($cfg->name, $task->branch);
                    if (null === $fresh) {
                        continue;
                    }
                    $ctx = new TaskCtx(task: $fresh, cfg: $cfg, store: $store, agents: $agents);
                    self::pollTask($ctx, $events);
                    if (null !== $store->get($cfg->name, $task->branch)) {
                        self::refreshDisplayCache($ctx, $agents);
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
