"""The task state machine — data-driven, one central table.

Every state, its on-enter action, and its display legend live in ``STATES``
below; adding or removing a state touches this table plus its handler,
nothing else. ``enter_state`` is the single shared on-enter handler used by
the automatic poller, the interactive commands, and the manual
``/pablo-state`` override alike (spec, "Manually forcing a state").
"""

from __future__ import annotations

from dataclasses import dataclass, field
from typing import Callable

from pablo import PabloError, agents, ghpr
from pablo.config import ProjectConfig
from pablo.model import (
    ALL_STATES,
    CI_RED,
    DRAFT,
    IN_PROGRESS,
    NEEDS_TESTING,
    READY_TO_REVIEW,
    REQUEST_CHANGES,
    TESTING_FAILED,
    WAITING,
    WAITING_REVIEW,
    Task,
    utcnow,
)
from pablo.store import Store

# /commit-and-pr is the single, uniform way work re-enters draft — valid
# from exactly these states (spec, "Task state").
COMMIT_ALLOWED_FROM = {IN_PROGRESS, CI_RED, REQUEST_CHANGES, TESTING_FAILED}

# The waiting toggle is forbidden from these two states.
WAITING_FORBIDDEN_FROM = {REQUEST_CHANGES, TESTING_FAILED}

# States whose on-enter action fires a fire-and-forget agent/startup launch
# via ``agents.launch``/``agents.run_startup_script``. The poller and
# ``pablo relaunch`` consult this set (and ``LAUNCH_SPECS``) to self-heal a
# cold-worktree Orca ``terminal create`` hang that left the worktree with
# no live session.
AGENT_LAUNCH_STATES = {IN_PROGRESS, CI_RED, REQUEST_CHANGES, TESTING_FAILED}


@dataclass
class TaskCtx:
    task: Task
    cfg: ProjectConfig
    store: Store
    previous_state: str | None = field(default=None, init=False)


def _repo_slug(cfg: ProjectConfig) -> str:
    from pablo.providers.github import repo_slug

    return repo_slug(cfg)


def _analyst_prompt(task: Task) -> str:
    if task.issue is not None:
        return (
            f"Analyze issue {task.issue.key} ({task.issue.url}) for this "
            f"worktree and produce your summary and action plan."
        )
    return (
        "No linked issue for this task. Apply your analysis methodology "
        f"directly to this task prompt instead: {task.prompt or task.summary or ''}"
    )


def _ci_analyst_prompt(task: Task) -> str:
    return (
        f"CI is failing on PR #{task.pr_number}. Analyze the failing "
        f"checks and produce a fix plan."
    )


def _pr_feedback_prompt(task: Task) -> str:
    return (
        f"Review feedback was left on PR #{task.pr_number}. Read the "
        f"unresolved review comments and produce your fix plan."
    )


def _task_feedback_prompt(task: Task) -> str:
    issue_ref = task.issue.key if task.issue else "the task"
    return (
        f"Manual testing failed for {issue_ref} (PR #{task.pr_number}). "
        f"Gather the testing feedback and produce your fix plan."
    )


def _launch_tracked(ctx: "TaskCtx", label: str, fn: Callable, *args) -> None:
    """Fire a non-blocking launch and stamp it in ``agent_launches``.

    All four agent-launching states route their ``agents.launch`` /
    ``agents.run_startup_script`` calls through here so the poller and
    ``pablo relaunch`` share a single per-label record (``launched_at`` +
    ``attempts``) to self-heal a cold-worktree Orca hang.
    """
    fn(*args)
    ctx.task.agent_launches[label] = {"launched_at": utcnow(), "attempts": 1}


def _enter_in_progress(ctx: TaskCtx) -> None:
    if not ctx.task.task_analyst_ran:
        _launch_tracked(
            ctx, "task-analyst", agents.launch,
            ctx.task.worktree_path, "task-analyst", _analyst_prompt(ctx.task),
            ctx.task.project, ctx.task.branch,
        )
        ctx.task.task_analyst_ran = True
    if ctx.cfg.startup_script and not ctx.task.startup_script_ran:
        _launch_tracked(
            ctx, "startup-script", agents.run_startup_script,
            ctx.task.worktree_path, ctx.cfg.startup_script,
            ctx.task.project, ctx.task.branch,
        )
        ctx.task.startup_script_ran = True


def _enter_waiting(ctx: TaskCtx) -> None:
    ctx.task.state_before_waiting = ctx.previous_state


def _enter_draft(ctx: TaskCtx) -> None:
    ctx.task.ci_ignored = False


def _enter_ready_to_review(ctx: TaskCtx) -> None:
    if ctx.task.pr_number is not None:
        ghpr.mark_ready(_repo_slug(ctx.cfg), ctx.task.pr_number)
    # Momentary state: immediately settle into waiting-review.
    enter_state(ctx, WAITING_REVIEW)


def _current_issue_status(ctx: TaskCtx) -> str | None:
    """Best-effort tracker status, for seeding the observed-transition
    baseline. A tracker hiccup must never break the state transition."""
    if ctx.task.issue is None or not ctx.cfg.failure_signal:
        return None
    try:
        from pablo.providers import get_provider

        provider = get_provider(ctx.cfg.provider)
        if not getattr(provider, "signal_via_status", False):
            return None
        return provider.issue_status(ctx.task.issue.key, ctx.cfg)
    except Exception:
        return None


def _enter_needs_testing(ctx: TaskCtx) -> None:
    # The entry timestamp is the failure-signal baseline for this round.
    # Restoring from a pause must NOT move it: signals applied during the
    # pause are deferred, not lost (spec, the `waiting` bullet).
    if ctx.previous_state == WAITING and ctx.task.needs_testing_entered_at:
        return
    ctx.task.needs_testing_entered_at = utcnow()
    # Seed the observed-transition fallback: a status already sitting on
    # the failure signal at entry (stale, from a previous round) must not
    # fire — only a transition observed after this point counts.
    ctx.task.last_seen_issue_status = _current_issue_status(ctx)


def _mark_draft_then_run_agent(ctx: TaskCtx, label: str, prompt: str) -> None:
    if ctx.task.pr_number is not None:
        ghpr.mark_draft(_repo_slug(ctx.cfg), ctx.task.pr_number)
    _launch_tracked(
        ctx, label, agents.launch,
        ctx.task.worktree_path, label, prompt,
        ctx.task.project, ctx.task.branch,
    )


def _enter_request_changes(ctx: TaskCtx) -> None:
    _mark_draft_then_run_agent(ctx, "pr-feedback", _pr_feedback_prompt(ctx.task))


def _enter_ci_red(ctx: TaskCtx) -> None:
    _launch_tracked(
        ctx, "ci-analyst", agents.launch,
        ctx.task.worktree_path, "ci-analyst", _ci_analyst_prompt(ctx.task),
        ctx.task.project, ctx.task.branch,
    )


def _enter_testing_failed(ctx: TaskCtx) -> None:
    _mark_draft_then_run_agent(ctx, "task-feedback", _task_feedback_prompt(ctx.task))


@dataclass(frozen=True)
class StateDef:
    name: str
    emoji: str
    label: str
    on_enter: Callable[[TaskCtx], None] | None
    polled: bool  # the state poller evaluates transitions in this state


STATES: dict[str, StateDef] = {
    IN_PROGRESS: StateDef(IN_PROGRESS, "🔨", "in-progress", _enter_in_progress, False),
    WAITING: StateDef(WAITING, "🥱", "waiting", _enter_waiting, False),
    DRAFT: StateDef(DRAFT, "📝", "draft", _enter_draft, True),
    CI_RED: StateDef(CI_RED, "🔴", "ci-red", _enter_ci_red, True),
    # Momentary pass-through: displayed as waiting-review if ever observed.
    READY_TO_REVIEW: StateDef(
        READY_TO_REVIEW, "👀", "waiting-review", _enter_ready_to_review, True
    ),
    WAITING_REVIEW: StateDef(WAITING_REVIEW, "👀", "waiting-review", None, True),
    NEEDS_TESTING: StateDef(
        NEEDS_TESTING, "🧪", "needs-testing", _enter_needs_testing, True
    ),
    REQUEST_CHANGES: StateDef(
        REQUEST_CHANGES, "🔁", "request-changes", _enter_request_changes, False
    ),
    TESTING_FAILED: StateDef(
        TESTING_FAILED, "🚨", "testing-failed", _enter_testing_failed, False
    ),
}

assert set(STATES) == set(ALL_STATES)


@dataclass(frozen=True)
class _LaunchSpec:
    """How to re-fire a label's launch for self-healing / ``pablo relaunch``.

    ``build(ctx)`` returns the ``(callable, args)`` to invoke — the on-enter
    handlers stay the sole authors of run-once guards and PR-draft side
    effects; this table only reconstructs the fire-and-forget launch call.
    """

    label: str
    build: Callable[["TaskCtx"], tuple[Callable, tuple]]


def _specs_for(ctx: TaskCtx, state: str) -> list[_LaunchSpec]:
    """The active launch specs for ``state`` under ``ctx`` (startup-script
    only when the project configures one)."""
    specs = list(LAUNCH_SPECS.get(state, []))
    if state == IN_PROGRESS and not ctx.cfg.startup_script:
        specs = [s for s in specs if s.label != "startup-script"]
    return specs


LAUNCH_SPECS: dict[str, list[_LaunchSpec]] = {
    IN_PROGRESS: [
        _LaunchSpec(
            "task-analyst",
            lambda c: (agents.launch, (c.task.worktree_path, "task-analyst", _analyst_prompt(c.task), c.task.project, c.task.branch)),
        ),
        _LaunchSpec(
            "startup-script",
            lambda c: (agents.run_startup_script, (c.task.worktree_path, c.cfg.startup_script, c.task.project, c.task.branch)),
        ),
    ],
    CI_RED: [
        _LaunchSpec(
            "ci-analyst",
            lambda c: (agents.launch, (c.task.worktree_path, "ci-analyst", _ci_analyst_prompt(c.task), c.task.project, c.task.branch)),
        ),
    ],
    REQUEST_CHANGES: [
        _LaunchSpec(
            "pr-feedback",
            lambda c: (agents.launch, (c.task.worktree_path, "pr-feedback", _pr_feedback_prompt(c.task), c.task.project, c.task.branch)),
        ),
    ],
    TESTING_FAILED: [
        _LaunchSpec(
            "task-feedback",
            lambda c: (agents.launch, (c.task.worktree_path, "task-feedback", _task_feedback_prompt(c.task), c.task.project, c.task.branch)),
        ),
    ],
}


def enter_state(ctx: TaskCtx, target: str, *, trigger: bool = True) -> None:
    """Switch the task to ``target`` and run its on-enter action.

    ``trigger=False`` (the ``--no-trigger`` flag) skips the on-enter action —
    except for ``waiting``, whose action (saving the previous state) is what
    makes the pause restorable, so it always runs.
    """
    state_def = STATES.get(target)
    if state_def is None:
        raise PabloError(f"unknown state {target!r} (expected one of {list(STATES)})")
    if target == WAITING and ctx.task.state in WAITING_FORBIDDEN_FROM:
        raise PabloError(
            f"going from {ctx.task.state} to waiting is impossible — address "
            f"the feedback and use /commit-and-pr instead"
        )
    ctx.previous_state = ctx.task.state
    ctx.task.state = target
    ctx.task.state_entered_at = utcnow()
    if state_def.on_enter is not None and (trigger or target == WAITING):
        state_def.on_enter(ctx)
    ctx.store.save(ctx.task)


def toggle_waiting(ctx: TaskCtx) -> str:
    """The /pablo-waiting toggle. Returns the state the task ended up in."""
    if ctx.task.state == WAITING:
        target = ctx.task.state_before_waiting or IN_PROGRESS
        ctx.task.state_before_waiting = None
        enter_state(ctx, target)
    else:
        enter_state(ctx, WAITING)
    return ctx.task.state
