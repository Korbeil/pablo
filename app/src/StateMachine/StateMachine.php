<?php

declare(strict_types=1);

namespace Pablo\StateMachine;

use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Hooks\Hooks;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * The task state machine — data-driven, one central table.
 *
 * Every state's on-enter action lives in onEnter() and the agents each
 * state launches in AGENTS_BY_STATE; enterState() is the single shared
 * on-enter handler used by the poller, the commands, and the manual
 * /pablo-state override.
 */
final class StateMachine
{
    // /pablo-commit-and-pr is the single, uniform way work re-enters draft.
    public const COMMIT_ALLOWED_FROM = [
        State::InProgress,
        State::CiRed,
        State::RequestChanges,
        State::TestingFailed,
        State::WaitingReview,
        State::Approved,
    ];

    private const WAITING_FORBIDDEN_FROM = [State::RequestChanges, State::TestingFailed];

    /**
     * The agents each state launches on enter (and that task:relaunch and the
     * poller's self-heal re-fire), in launch order. task-analyst and the
     * startup script run once per task; the startup script only when the
     * project configures one.
     */
    private const AGENTS_BY_STATE = [
        State::InProgress->value => [Agent::TaskAnalyst, Agent::StartupScript],
        State::CiRed->value => [Agent::CiAnalyst],
        State::RequestChanges->value => [Agent::PrFeedback],
        State::TestingFailed->value => [Agent::TaskFeedback],
    ];

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly ProviderRegistryInterface $providers,
        private readonly RepoSlug $repoSlug,
        private readonly Time $time,
        private readonly Hooks $hooks,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
    }

    /** Nesting of enterState() calls: >0 while a pass-through state chains on. */
    private int $depth = 0;

    /** @var list<State> states entered by the current outermost enterState() */
    private array $entered = [];

    private function onEnter(State $state): ?callable
    {
        return match ($state) {
            State::InProgress, State::CiRed => $this->launchStateAgents(...),
            State::Waiting => $this->enterWaiting(...),
            State::Draft => $this->enterDraft(...),
            // Momentary pass-through: settles into waiting-review.
            State::ReadyToReview => $this->enterReadyToReview(...),
            // Momentary pass-through when testing is enabled (chains to
            // needs-testing); terminal when testing.enabled: false — the
            // task sits there until merge auto-closes it.
            State::Approved => $this->enterApproved(...),
            State::NeedsTesting => $this->enterNeedsTesting(...),
            State::RequestChanges, State::TestingFailed => $this->markDraftThenLaunchStateAgents(...),
            // closed: the PR was closed without being merged. Nothing is
            // removed: the worktree stays until the user closes the task
            // (task:close or the dashboard).
            State::WaitingReview, State::Closed => null,
        };
    }

    // ------------------------------------------------------------ launches ---

    /** @return list<Agent> the agents $state launches, in launch order */
    public function agentsFor(State $state): array
    {
        return self::AGENTS_BY_STATE[$state->value] ?? [];
    }

    /**
     * Fire $agent on the task and record the launch (attempt $attempt).
     * False when there is nothing to fire: a startup script the project
     * does not configure.
     */
    public function launch(TaskCtx $ctx, Agent $agent, int $attempt = 1): bool
    {
        $task = $ctx->task;
        if (Agent::StartupScript === $agent) {
            if (null === $ctx->cfg->repo->startupScript) {
                return false;
            }
            $ctx->agents->runStartupScript($task->worktreePath, $ctx->cfg->repo->startupScript, $task->project, $task->branch);
        } else {
            $ctx->agents->launch($task->worktreePath, $agent, $this->promptFor($agent, $task), $task->project, $task->branch);
        }
        $task->agentLaunches[$agent->value] = new AgentLaunch($agent, $this->time->utcnow(), $attempt);

        return true;
    }

    /** The prompt a state agent is launched with. */
    public function promptFor(Agent $agent, Task $task): string
    {
        return match ($agent) {
            Agent::TaskAnalyst => null !== $task->issue
                ? "Analyze issue {$task->issue->key} ({$task->issue->url}) for this worktree and produce your summary and action plan."
                : 'No linked issue for this task. Apply your analysis methodology '
                    .'directly to this task prompt instead: '.($task->prompt ?? $task->summary ?? ''),
            Agent::CiAnalyst => "CI is failing on PR #{$task->prNumber}. Analyze the failing checks and produce a fix plan.",
            Agent::PrFeedback => "Review feedback was left on PR #{$task->prNumber}. Read the unresolved review comments and produce your fix plan.",
            Agent::TaskFeedback => 'Manual testing failed for '.(null !== $task->issue ? $task->issue->key : 'the task')." (PR #{$task->prNumber}). Gather the testing feedback and produce your fix plan.",
            Agent::StartupScript, Agent::RebaseConflictResolver, Agent::YoloImplement, Agent::YoloCommit => throw new PabloError("{$agent->value} is not launched with a state prompt"),
        };
    }

    /** Launch the current state's agents; the run-once ones never fire twice (e.g. on resume). */
    private function launchStateAgents(TaskCtx $ctx): void
    {
        $task = $ctx->task;
        foreach ($this->agentsFor($task->state) as $agent) {
            match ($agent) {
                Agent::TaskAnalyst => $task->taskAnalystRan = $task->taskAnalystRan || $this->launch($ctx, $agent),
                Agent::StartupScript => $task->startupScriptRan = $task->startupScriptRan || $this->launch($ctx, $agent),
                default => $this->launch($ctx, $agent),
            };
        }
    }

    private function markDraftThenLaunchStateAgents(TaskCtx $ctx): void
    {
        if (null !== $ctx->task->prNumber) {
            $this->gh->markDraft($this->repoSlug->for($ctx->cfg), $ctx->task->prNumber);
        }
        $this->launchStateAgents($ctx);
    }

    // ------------------------------------------------------- on-enter defs ---

    private function enterWaiting(TaskCtx $ctx): void
    {
        $ctx->task->stateBeforeWaiting = $ctx->previousState;
    }

    private function enterDraft(TaskCtx $ctx): void
    {
        $ctx->task->ciIgnored = false;
        $ctx->agents->refreshAgentDisplayCache($ctx->task->project, $ctx->task->branch, $ctx->task->worktreePath);
    }

    private function enterReadyToReview(TaskCtx $ctx): void
    {
        if (null !== $ctx->task->prNumber) {
            $this->gh->markReady($this->repoSlug->for($ctx->cfg), $ctx->task->prNumber);
        }
        // Momentary state: immediately settle into waiting-review.
        $this->enterState($ctx, State::WaitingReview);
    }

    private function enterApproved(TaskCtx $ctx): void
    {
        if (!$ctx->cfg->testing->enabled) {
            return; // terminal: the task waits here until merge auto-closes it
        }
        // Momentary state: immediately settle into needs-testing.
        $this->enterState($ctx, State::NeedsTesting);
    }

    private function currentIssueStatus(TaskCtx $ctx): ?string
    {
        if (null === $ctx->task->issue || null === $ctx->cfg->testing->failureSignal) {
            return null;
        }
        try {
            $provider = $this->providers->get($ctx->cfg->tracker->provider);
            if (!$provider->supportsSignalViaStatus()) {
                return null;
            }

            return $provider->issueStatus($ctx->task->issue->key, $ctx->cfg);
        } catch (\Throwable) {
            return null;
        }
    }

    private function enterNeedsTesting(TaskCtx $ctx): void
    {
        // The entry timestamp is the failure-signal baseline; restoring from a
        // pause must NOT move it (signals applied during the pause are deferred).
        if (State::Waiting === $ctx->previousState && null !== $ctx->task->needsTestingEnteredAt) {
            return;
        }
        $ctx->task->needsTestingEnteredAt = $this->time->utcnow();
        $ctx->task->lastSeenIssueStatus = $this->currentIssueStatus($ctx);
    }

    // -------------------------------------------------------- transitions ---

    public function enterState(TaskCtx $ctx, State $target, bool $trigger = true): void
    {
        if (State::Waiting === $target && \in_array($ctx->task->state, self::WAITING_FORBIDDEN_FROM, true)) {
            throw new PabloError("going from {$ctx->task->state->value} to waiting is impossible — address ".'the feedback and use /pablo-commit-and-pr instead');
        }
        if (State::NeedsTesting === $target && !$ctx->cfg->testing->enabled) {
            throw new PabloError('needs-testing is disabled for this project (testing.enabled: false)');
        }
        // A pass-through state (ready-to-review, approved) re-enters this
        // method from its on-enter: only the outermost call fires the user's
        // hook, once, for where the task finally settled.
        $outermost = 0 === $this->depth;
        if ($outermost) {
            $this->entered = [];
        }
        $from = $ctx->task->state;
        ++$this->depth;
        try {
            $ctx->previousState = $ctx->task->state;
            $ctx->task->state = $target;
            $ctx->task->stateEnteredAt = $this->time->utcnow();
            $this->entered[] = $target;
            $onEnter = $this->onEnter($target);
            if (null !== $onEnter && ($trigger || State::Waiting === $target)) {
                $onEnter($ctx);
            }
            $ctx->store->save($ctx->task);
            // Only after a successful save: a transition that failed to persist
            // must not pollute the analytics timeline.
            $this->analytics->stateEntered($ctx->task, $ctx->previousState);
        } finally {
            --$this->depth;
        }
        if ($outermost) {
            // Same rule as analytics: only a persisted transition notifies.
            $this->hooks->transition($ctx->cfg, $ctx->task, $from, $this->entered);
        }
    }

    public function toggleWaiting(TaskCtx $ctx): State
    {
        if (State::Waiting === $ctx->task->state) {
            $target = $ctx->task->stateBeforeWaiting ?? State::InProgress;
            $ctx->task->stateBeforeWaiting = null;
            $this->enterState($ctx, $target);
        } else {
            $this->enterState($ctx, State::Waiting);
        }

        return $ctx->task->state;
    }
}
