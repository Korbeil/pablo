<?php

declare(strict_types=1);

namespace Pablo\StateMachine;

use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPr;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;

/**
 * The task state machine — data-driven, one central table.
 *
 * Every state's on-enter action and display legend live here via the
 * `states()` table; enterState() is the single shared on-enter handler used
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

    /**
     * state => ['emoji','label','onEnter'=>?callable,'polled'=>bool].
     *
     * @return array<State, array{emoji: string, label: string, onEnter: ?callable, polled: bool}>
     */
    public static function states(): array
    {
        return [
            State::InProgress->value => ['emoji' => '🔨', 'label' => 'in-progress', 'onEnter' => [self::class, 'enterInProgress'], 'polled' => false],
            State::Waiting->value => ['emoji' => '🥱', 'label' => 'waiting', 'onEnter' => [self::class, 'enterWaiting'], 'polled' => false],
            State::Draft->value => ['emoji' => '📝', 'label' => 'draft', 'onEnter' => [self::class, 'enterDraft'], 'polled' => true],
            State::CiRed->value => ['emoji' => '🔴', 'label' => 'ci-red', 'onEnter' => [self::class, 'enterCiRed'], 'polled' => true],
            // Momentary pass-through: displayed as waiting-review if ever seen.
            State::ReadyToReview->value => ['emoji' => '👀', 'label' => 'waiting-review', 'onEnter' => [self::class, 'enterReadyToReview'], 'polled' => true],
            State::WaitingReview->value => ['emoji' => '👀', 'label' => 'waiting-review', 'onEnter' => null, 'polled' => true],
            State::NeedsTesting->value => ['emoji' => '🧪', 'label' => 'needs-testing', 'onEnter' => [self::class, 'enterNeedsTesting'], 'polled' => true],
            State::RequestChanges->value => ['emoji' => '🔁', 'label' => 'request-changes', 'onEnter' => [self::class, 'enterRequestChanges'], 'polled' => false],
            State::TestingFailed->value => ['emoji' => '🚨', 'label' => 'testing-failed', 'onEnter' => [self::class, 'enterTestingFailed'], 'polled' => false],
        ];
    }

    // ------------------------------------------------------------- prompts ---

    public static function analystPrompt(Task $task): string
    {
        if (null !== $task->issue) {
            return "Analyze issue {$task->issue->key} ({$task->issue->url}) for this worktree and produce your summary and action plan.";
        }

        return 'No linked issue for this task. Apply your analysis methodology '
            .'directly to this task prompt instead: '.($task->prompt ?? $task->summary ?? '');
    }

    public static function ciAnalystPrompt(Task $task): string
    {
        return "CI is failing on PR #{$task->prNumber}. Analyze the failing checks and produce a fix plan.";
    }

    public static function prFeedbackPrompt(Task $task): string
    {
        return "Review feedback was left on PR #{$task->prNumber}. Read the unresolved review comments and produce your fix plan.";
    }

    public static function taskFeedbackPrompt(Task $task): string
    {
        $issueRef = null !== $task->issue ? $task->issue->key : 'the task';

        return "Manual testing failed for {$issueRef} (PR #{$task->prNumber}). Gather the testing feedback and produce your fix plan.";
    }

    /**
     * @param list<mixed> $args
     */
    private static function launchTracked(TaskCtx $ctx, Agent $agent, callable $fn, array $args): void
    {
        $fn(...$args);
        $ctx->task->agentLaunches[$agent->value] = new AgentLaunch($agent, Time::utcnow(), 1);
    }

    // ------------------------------------------------------- on-enter defs ---

    public static function enterInProgress(TaskCtx $ctx): void
    {
        if (!$ctx->task->taskAnalystRan) {
            self::launchTracked($ctx, Agent::TaskAnalyst, $ctx->agents->launch(...), [
                $ctx->task->worktreePath,
                Agent::TaskAnalyst,
                self::analystPrompt($ctx->task),
                $ctx->task->project,
                $ctx->task->branch,
            ]);
            $ctx->task->taskAnalystRan = true;
        }
        if (null !== $ctx->cfg->startupScript && !$ctx->task->startupScriptRan) {
            self::launchTracked($ctx, Agent::StartupScript, $ctx->agents->runStartupScript(...), [
                $ctx->task->worktreePath,
                $ctx->cfg->startupScript,
                $ctx->task->project,
                $ctx->task->branch,
            ]);
            $ctx->task->startupScriptRan = true;
        }
    }

    public static function enterWaiting(TaskCtx $ctx): void
    {
        $ctx->task->stateBeforeWaiting = $ctx->previousState;
    }

    public static function enterDraft(TaskCtx $ctx): void
    {
        $ctx->task->ciIgnored = false;
        $ctx->agents->refreshAgentDisplayCache($ctx->task->project, $ctx->task->branch, $ctx->task->worktreePath);
    }

    public static function enterReadyToReview(TaskCtx $ctx): void
    {
        if (null !== $ctx->task->prNumber) {
            GhPr::markReady(RepoSlug::for($ctx->cfg), $ctx->task->prNumber);
        }
        // Momentary state: immediately settle into waiting-review.
        self::enterState($ctx, State::WaitingReview);
    }

    private static function currentIssueStatus(TaskCtx $ctx): ?string
    {
        if (null === $ctx->task->issue || null === $ctx->cfg->failureSignal) {
            return null;
        }
        try {
            $provider = ProviderRegistry::get($ctx->cfg->provider);
            if (!$provider->supportsSignalViaStatus()) {
                return null;
            }

            return $provider->issueStatus($ctx->task->issue->key, $ctx->cfg);
        } catch (\Throwable) {
            return null;
        }
    }

    public static function enterNeedsTesting(TaskCtx $ctx): void
    {
        // The entry timestamp is the failure-signal baseline; restoring from a
        // pause must NOT move it (signals applied during the pause are deferred).
        if (State::Waiting === $ctx->previousState && null !== $ctx->task->needsTestingEnteredAt) {
            return;
        }
        $ctx->task->needsTestingEnteredAt = Time::utcnow();
        $ctx->task->lastSeenIssueStatus = self::currentIssueStatus($ctx);
    }

    private static function markDraftThenRunAgent(TaskCtx $ctx, Agent $agent, string $prompt): void
    {
        if (null !== $ctx->task->prNumber) {
            GhPr::markDraft(RepoSlug::for($ctx->cfg), $ctx->task->prNumber);
        }
        self::launchTracked($ctx, $agent, $ctx->agents->launch(...), [
            $ctx->task->worktreePath,
            $agent,
            $prompt,
            $ctx->task->project,
            $ctx->task->branch,
        ]);
    }

    public static function enterRequestChanges(TaskCtx $ctx): void
    {
        self::markDraftThenRunAgent($ctx, Agent::PrFeedback, self::prFeedbackPrompt($ctx->task));
    }

    public static function enterCiRed(TaskCtx $ctx): void
    {
        self::launchTracked($ctx, Agent::CiAnalyst, $ctx->agents->launch(...), [
            $ctx->task->worktreePath,
            Agent::CiAnalyst,
            self::ciAnalystPrompt($ctx->task),
            $ctx->task->project,
            $ctx->task->branch,
        ]);
    }

    public static function enterTestingFailed(TaskCtx $ctx): void
    {
        self::markDraftThenRunAgent($ctx, Agent::TaskFeedback, self::taskFeedbackPrompt($ctx->task));
    }

    // -------------------------------------------------------- launch specs ---

    /**
     * @return array<string, callable(TaskCtx): array{0: callable, 1: array<int, mixed>}>
     */
    public static function launchSpecs(): array
    {
        return [
            Agent::TaskAnalyst->value => static fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::TaskAnalyst,
                self::analystPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::StartupScript->value => static fn (TaskCtx $c) => [$c->agents->runStartupScript(...), [
                $c->task->worktreePath,
                $c->cfg->startupScript,
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::CiAnalyst->value => static fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::CiAnalyst,
                self::ciAnalystPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::PrFeedback->value => static fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::PrFeedback,
                self::prFeedbackPrompt($c->task),
                $c->task->project,
                $c->task->branch,
            ]],
            Agent::TaskFeedback->value => static fn (TaskCtx $c) => [$c->agents->launch(...), [
                $c->task->worktreePath,
                Agent::TaskFeedback,
                self::taskFeedbackPrompt($c->task),
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
    public static function specsFor(TaskCtx $ctx, State $state): array
    {
        $byState = [
            State::InProgress->value => [Agent::TaskAnalyst, Agent::StartupScript],
            State::CiRed->value => [Agent::CiAnalyst],
            State::RequestChanges->value => [Agent::PrFeedback],
            State::TestingFailed->value => [Agent::TaskFeedback],
        ];
        $specs = [];
        $all = self::launchSpecs();
        foreach ($byState[$state->value] ?? [] as $agent) {
            if (Agent::StartupScript === $agent && null === $ctx->cfg->startupScript) {
                continue;
            }
            $specs[] = ['label' => $agent, 'build' => $all[$agent->value]];
        }

        return $specs;
    }

    // -------------------------------------------------------- transitions ---

    public static function enterState(TaskCtx $ctx, State $target, bool $trigger = true): void
    {
        $states = self::states();
        $stateDef = $states[$target->value] ?? null;
        if (null === $stateDef) {
            throw new PabloError('unknown state '.var_export($target->value, true));
        }
        if (State::Waiting === $target && \in_array($ctx->task->state, self::WAITING_FORBIDDEN_FROM, true)) {
            throw new PabloError("going from {$ctx->task->state->value} to waiting is impossible — address ".'the feedback and use /commit-and-pr instead');
        }
        $ctx->previousState = $ctx->task->state;
        $ctx->task->state = $target;
        $ctx->task->stateEnteredAt = Time::utcnow();
        if (null !== $stateDef['onEnter'] && ($trigger || State::Waiting === $target)) {
            ($stateDef['onEnter'])($ctx);
        }
        $ctx->store->save($ctx->task);
    }

    public static function toggleWaiting(TaskCtx $ctx): State
    {
        if (State::Waiting === $ctx->task->state) {
            $target = $ctx->task->stateBeforeWaiting ?? State::InProgress;
            $ctx->task->stateBeforeWaiting = null;
            self::enterState($ctx, $target);
        } else {
            self::enterState($ctx, State::Waiting);
        }

        return $ctx->task->state;
    }
}
