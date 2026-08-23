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
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * The task state machine — data-driven, one central table.
 *
 * Every state's on-enter action and display legend live here via the
 * states() table; enterState() is the single shared on-enter handler used
 * by the poller, the commands, and the manual /pablo-state override.
 */
final class StateMachine
{
    // /commit-and-pr is the single, uniform way work re-enters draft.
    public const COMMIT_ALLOWED_FROM = [
        State::InProgress,
        State::CiRed,
        State::RequestChanges,
        State::TestingFailed,
    ];

    public const WAITING_FORBIDDEN_FROM = [State::RequestChanges, State::TestingFailed];

    public const AGENT_LAUNCH_STATES = [
        State::InProgress,
        State::CiRed,
        State::RequestChanges,
        State::TestingFailed,
    ];

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly ProviderRegistryInterface $providers,
        private readonly RepoSlug $repoSlug,
        private readonly Time $time,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
    }

    /**
     * state => ['emoji','label','onEnter'=>?callable,'polled'=>bool].
     *
     * @return array<State, array{emoji: string, label: string, onEnter: ?callable, polled: bool}>
     */
    public function states(): array
    {
        return [
            State::InProgress->value => ['emoji' => '🔨', 'label' => 'in-progress', 'onEnter' => $this->enterInProgress(...), 'polled' => false],
            State::Waiting->value => ['emoji' => '🥱', 'label' => 'waiting', 'onEnter' => $this->enterWaiting(...), 'polled' => false],
            State::Draft->value => ['emoji' => '📝', 'label' => 'draft', 'onEnter' => $this->enterDraft(...), 'polled' => true],
            State::CiRed->value => ['emoji' => '🔴', 'label' => 'ci-red', 'onEnter' => $this->enterCiRed(...), 'polled' => true],
            // Momentary pass-through: displayed as waiting-review if ever seen.
            State::ReadyToReview->value => ['emoji' => '👀', 'label' => 'waiting-review', 'onEnter' => $this->enterReadyToReview(...), 'polled' => true],
            State::WaitingReview->value => ['emoji' => '👀', 'label' => 'waiting-review', 'onEnter' => null, 'polled' => true],
            State::NeedsTesting->value => ['emoji' => '🧪', 'label' => 'needs-testing', 'onEnter' => $this->enterNeedsTesting(...), 'polled' => true],
            State::RequestChanges->value => ['emoji' => '🔁', 'label' => 'request-changes', 'onEnter' => $this->enterRequestChanges(...), 'polled' => false],
            State::TestingFailed->value => ['emoji' => '🚨', 'label' => 'testing-failed', 'onEnter' => $this->enterTestingFailed(...), 'polled' => false],
        ];
    }

    // ------------------------------------------------------------- prompts ---

    public function analystPrompt(Task $task): string
    {
        if (null !== $task->issue) {
            return "Analyze issue {$task->issue->key} ({$task->issue->url}) for this worktree and produce your summary and action plan.";
        }

        return 'No linked issue for this task. Apply your analysis methodology '
            .'directly to this task prompt instead: '.($task->prompt ?? $task->summary ?? '');
    }

    public function ciAnalystPrompt(Task $task): string
    {
        return "CI is failing on PR #{$task->prNumber}. Analyze the failing checks and produce a fix plan.";
    }

    public function prFeedbackPrompt(Task $task): string
    {
        return "Review feedback was left on PR #{$task->prNumber}. Read the unresolved review comments and produce your fix plan.";
    }

    public function taskFeedbackPrompt(Task $task): string
    {
        $issueRef = null !== $task->issue ? $task->issue->key : 'the task';

        return "Manual testing failed for {$issueRef} (PR #{$task->prNumber}). Gather the testing feedback and produce your fix plan.";
    }

    /**
     * @param list<mixed> $args
     */
    private function launchTracked(TaskCtx $ctx, Agent $agent, callable $fn, array $args): void
    {
        $fn(...$args);
        $ctx->task->agentLaunches[$agent->value] = new AgentLaunch($agent, $this->time->utcnow(), 1);
    }

    // ------------------------------------------------------- on-enter defs ---

    public function enterInProgress(TaskCtx $ctx): void
    {
        if (!$ctx->task->taskAnalystRan) {
            $this->launchTracked($ctx, Agent::TaskAnalyst, $ctx->agents->launch(...), [
                $ctx->task->worktreePath,
                Agent::TaskAnalyst,
                $this->analystPrompt($ctx->task),
                $ctx->task->project,
                $ctx->task->branch,
            ]);
            $ctx->task->taskAnalystRan = true;
        }
        if (null !== $ctx->cfg->startupScript && !$ctx->task->startupScriptRan) {
            $this->launchTracked($ctx, Agent::StartupScript, $ctx->agents->runStartupScript(...), [
                $ctx->task->worktreePath,
                $ctx->cfg->startupScript,
                $ctx->task->project,
                $ctx->task->branch,
            ]);
            $ctx->task->startupScriptRan = true;
        }
    }

    public function enterWaiting(TaskCtx $ctx): void
    {
        $ctx->task->stateBeforeWaiting = $ctx->previousState;
    }

    public function enterDraft(TaskCtx $ctx): void
    {
        $ctx->task->ciIgnored = false;
        $ctx->agents->refreshAgentDisplayCache($ctx->task->project, $ctx->task->branch, $ctx->task->worktreePath);
    }

    public function enterReadyToReview(TaskCtx $ctx): void
    {
        if (null !== $ctx->task->prNumber) {
            $this->gh->markReady($this->repoSlug->for($ctx->cfg), $ctx->task->prNumber);
        }
        // Momentary state: immediately settle into waiting-review.
        $this->enterState($ctx, State::WaitingReview);
    }

    private function currentIssueStatus(TaskCtx $ctx): ?string
    {
        if (null === $ctx->task->issue || null === $ctx->cfg->failureSignal) {
            return null;
        }
        try {
            $provider = $this->providers->get($ctx->cfg->provider);
            if (!$provider->supportsSignalViaStatus()) {
                return null;
            }

            return $provider->issueStatus($ctx->task->issue->key, $ctx->cfg);
        } catch (\Throwable) {
            return null;
        }
    }

    public function enterNeedsTesting(TaskCtx $ctx): void
    {
        // The entry timestamp is the failure-signal baseline; restoring from a
        // pause must NOT move it (signals applied during the pause are deferred).
        if (State::Waiting === $ctx->previousState && null !== $ctx->task->needsTestingEnteredAt) {
            return;
        }
        $ctx->task->needsTestingEnteredAt = $this->time->utcnow();
        $ctx->task->lastSeenIssueStatus = $this->currentIssueStatus($ctx);
    }

    private function markDraftThenRunAgent(TaskCtx $ctx, Agent $agent, string $prompt): void
    {
        if (null !== $ctx->task->prNumber) {
            $this->gh->markDraft($this->repoSlug->for($ctx->cfg), $ctx->task->prNumber);
        }
        $this->launchTracked($ctx, $agent, $ctx->agents->launch(...), [
            $ctx->task->worktreePath,
            $agent,
            $prompt,
            $ctx->task->project,
            $ctx->task->branch,
        ]);
    }

    public function enterRequestChanges(TaskCtx $ctx): void
    {
        $this->markDraftThenRunAgent($ctx, Agent::PrFeedback, $this->prFeedbackPrompt($ctx->task));
    }

    public function enterCiRed(TaskCtx $ctx): void
    {
        $this->launchTracked($ctx, Agent::CiAnalyst, $ctx->agents->launch(...), [
            $ctx->task->worktreePath,
            Agent::CiAnalyst,
            $this->ciAnalystPrompt($ctx->task),
            $ctx->task->project,
            $ctx->task->branch,
        ]);
    }

    public function enterTestingFailed(TaskCtx $ctx): void
    {
        $this->markDraftThenRunAgent($ctx, Agent::TaskFeedback, $this->taskFeedbackPrompt($ctx->task));
    }

    // -------------------------------------------------------- launch specs ---

    /**
     * @return array<string, callable(TaskCtx): array{0: callable, 1: array<int, mixed>}>
     */
    public function launchSpecs(): array
    {
        return [
            Agent::TaskAnalyst->value => fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::TaskAnalyst,
                $this->analystPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::StartupScript->value => static fn (TaskCtx $c) => [$c->agents->runStartupScript(...), [
                $c->task->worktreePath,
                $c->cfg->startupScript,
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::CiAnalyst->value => fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::CiAnalyst,
                $this->ciAnalystPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::PrFeedback->value => fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::PrFeedback,
                $this->prFeedbackPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::TaskFeedback->value => fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::TaskFeedback,
                $this->taskFeedbackPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
        ];
    }

    /**
     * The active launch specs for $state under $ctx (startup-script only when
     * the project configures one; task-analyst only for in-progress).
     *
     * @return array<int, array{label: Agent, build: callable}>
     */
    public function specsFor(TaskCtx $ctx, State $state): array
    {
        $byState = [
            State::InProgress->value => [Agent::TaskAnalyst, Agent::StartupScript],
            State::CiRed->value => [Agent::CiAnalyst],
            State::RequestChanges->value => [Agent::PrFeedback],
            State::TestingFailed->value => [Agent::TaskFeedback],
        ];
        $specs = [];
        $all = $this->launchSpecs();
        foreach ($byState[$state->value] ?? [] as $agent) {
            if (Agent::StartupScript === $agent && null === $ctx->cfg->startupScript) {
                continue;
            }
            $specs[] = ['label' => $agent, 'build' => $all[$agent->value]];
        }

        return $specs;
    }

    // -------------------------------------------------------- transitions ---

    public function enterState(TaskCtx $ctx, State $target, bool $trigger = true): void
    {
        $states = $this->states();
        $stateDef = $states[$target->value] ?? null;
        if (null === $stateDef) {
            throw new PabloError('unknown state '.var_export($target->value, true));
        }
        if (State::Waiting === $target && \in_array($ctx->task->state, self::WAITING_FORBIDDEN_FROM, true)) {
            throw new PabloError("going from {$ctx->task->state->value} to waiting is impossible — address ".'the feedback and use /commit-and-pr instead');
        }
        $ctx->previousState = $ctx->task->state;
        $ctx->task->state = $target;
        $ctx->task->stateEnteredAt = $this->time->utcnow();
        if (null !== $stateDef['onEnter'] && ($trigger || State::Waiting === $target)) {
            ($stateDef['onEnter'])($ctx);
        }
        $ctx->store->save($ctx->task);
        // Only after a successful save: a transition that failed to persist
        // must not pollute the analytics timeline.
        $this->analytics->stateEntered($ctx->task, $ctx->previousState);
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
