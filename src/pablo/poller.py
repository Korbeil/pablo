"""Post-draft state polling and merge auto-close (spec, "Post-draft state
transitions" and "Closing a task").

Runs per project on the ``state_polling.interval_minutes`` cadence via the
dispatcher. Per task, under the task lock:

1. merged flag already set → only wait for agents to finish, then close;
2. merge detection (runs even while ``waiting``);
3. ``waiting`` → nothing else is evaluated (events are deferred, not lost —
   the checks are timestamp-based);
4. otherwise the current state's checks from ``POLL_CHECKS``.

For every **agent-launching state** (``in-progress``, ``ci-red``,
``request-changes``, ``testing-failed`` — see ``AGENT_LAUNCH_STATES``),
the poller also self-heals a cold-worktree Orca launch hang: state entry is
non-blocking (it spawns a detached launcher and returns), so a hang inside
``orca terminal create`` leaves the launch tracking flag set but no agent
session ever appeared. The poller re-fires each detached launcher (agent
*and* the in-progress startup script — verified safely re-runnable) when
no session is observed past the launch window, up to
``LAUNCH_MAX_ATTEMPTS``. The Orca workspace ``displayName`` is likewise
re-set on the same heal pass (so it shows ``OMS-XXXX`` rather than the
lowercase branch that Orca auto-derives from the path). Post-draft states
still fall through to ``POLL_CHECKS`` after the heal, so e.g. a ``ci-red``
that went green transitions normally.

There is no git-push detection anywhere here: draft re-entry is exclusively
/commit-and-pr's job.
"""

from __future__ import annotations

from datetime import datetime, timezone
from pathlib import Path
from typing import Callable

from pablo import PabloError, agents, ghpr, gitrepo, listing
from pablo.config import ProjectConfig
from pablo.model import (
    CI_RED,
    DRAFT,
    IN_PROGRESS,
    NEEDS_TESTING,
    READY_TO_REVIEW,
    REQUEST_CHANGES,
    TESTING_FAILED,
    WAITING,
    WAITING_REVIEW,
    utcnow,
)
from pablo.providers import get_provider, parse_ts
from pablo.states import (
    AGENT_LAUNCH_STATES,
    LAUNCH_SPECS,
    TaskCtx,
    _specs_for,
    enter_state,
)
from pablo.store import Store, task_lock

POLL_LOCK_TIMEOUT_S = 2

# Self-healing window for a cold-worktree Orca launch hang. Must exceed the
# detached launcher's retry budget (ORCA_LAUNCH_RETRIES *
# ORCA_LAUNCH_RETRY_DELAY_S + warm-up ≈ 170s in agents.py) plus margin so a
# genuinely-running (but slow to register in Orca's `worktree ps`) session
# is observed before we conclude it died. The poller cadence itself bounds
# how quickly a stuck task is noticed regardless.
LAUNCH_WINDOW_S = 240
LAUNCH_MAX_ATTEMPTS = 3

EPOCH = datetime.fromtimestamp(0, tz=timezone.utc)


def _repo_slug(cfg: ProjectConfig) -> str:
    from pablo.providers.github import repo_slug

    return repo_slug(cfg)


# ------------------------------------------------------------- checks ----
# Each check returns the target state to enter, or None. First hit wins.

Check = Callable[[TaskCtx, str], "str | None"]


def check_ci_red(ctx: TaskCtx, slug: str) -> str | None:
    if ctx.task.ci_ignored:
        return None
    if ghpr.ci_status(slug, ctx.task.pr_number, ctx.cfg.ci_ignore_checks) == "red":
        return CI_RED
    return None


def check_ci_green(ctx: TaskCtx, slug: str) -> str | None:
    if ghpr.ci_status(slug, ctx.task.pr_number, ctx.cfg.ci_ignore_checks) == "green":
        return READY_TO_REVIEW
    return None


def check_reviews(ctx: TaskCtx, slug: str) -> str | None:
    anchor = ghpr.ready_anchor(slug, ctx.task.pr_number)
    author, reviews = ghpr.fetch_reviews(slug, ctx.task.pr_number)
    verdict = ghpr.evaluate_reviews(
        reviews, anchor=anchor, author=author, bot_whitelist=ctx.cfg.bot_whitelist
    )
    if verdict == "approved":
        return NEEDS_TESTING
    if verdict == "changes":
        return REQUEST_CHANGES
    return None


def check_failure_signal(ctx: TaskCtx, slug: str) -> str | None:
    task = ctx.task
    if not ctx.cfg.failure_signal or not task.needs_testing_entered_at:
        return None
    baseline = parse_ts(task.needs_testing_entered_at)
    last_handled = (
        parse_ts(task.last_handled_signal_at) if task.last_handled_signal_at else EPOCH
    )
    provider = get_provider(ctx.cfg.provider)
    events = [
        stamp
        for stamp in provider.failure_signal_events(task, ctx.cfg)
        if stamp > baseline and stamp > last_handled
    ]
    if events:
        task.last_handled_signal_at = max(events).isoformat()
        return TESTING_FAILED

    # Observed-transition fallback for providers without event history
    # (e.g. Jira via MCP when responses carry no changelog): seeing the
    # issue *enter* the failure status between two polls counts as one
    # event, stamped at observation time. `last_seen_issue_status` is
    # seeded on needs-testing entry, so a stale signal status never fires.
    if getattr(provider, "signal_via_status", False) and task.issue is not None:
        current = provider.issue_status(task.issue.key, ctx.cfg)
        previous = task.last_seen_issue_status
        task.last_seen_issue_status = current
        ctx.store.save(task)
        if current == ctx.cfg.failure_signal and previous != ctx.cfg.failure_signal:
            task.last_handled_signal_at = utcnow()
            return TESTING_FAILED
    return None


# The one central transition table for the poller. `waiting-review` checks
# CI first: a red CI (e.g. after a sync rebase push) outranks review state.
POLL_CHECKS: dict[str, list[Check]] = {
    DRAFT: [check_ci_red, check_ci_green],
    CI_RED: [check_ci_green],
    READY_TO_REVIEW: [check_ci_red, check_reviews],
    WAITING_REVIEW: [check_ci_red, check_reviews],
    NEEDS_TESTING: [check_failure_signal],
}


# -------------------------------------------------------------- polling ---


def _close(ctx: TaskCtx, events: list[str]) -> None:
    task, cfg = ctx.task, ctx.cfg
    gitrepo.remove_worktree(cfg.repo_path, task.worktree_path, task.branch)
    ctx.store.delete(task.project, task.branch)
    events.append(f"{task.branch}: PR merged → task closed, worktree removed")


def _relaunch_stuck_agents(ctx: TaskCtx, events: list[str]) -> None:
    """Re-fire any detached launcher whose cold-worktree Orca hang left the
    worktree with no agent session.

    Applies to every agent-launching state (``AGENT_LAUNCH_STATES``). State
    entry is non-blocking (it spawns a detached launcher and returns), but
    ``orca terminal create`` on a just-touched worktree has been observed to
    hang, leaving the launch tracking record set with no session ever
    appearing. Once the launch window has elapsed with no observed session
    and the attempt budget isn't exhausted, each spec's launcher is re-fired
    (same fire-and-forget detached call); the worktree is now warm, so the
    retry usually succeeds in Orca. For ``in-progress`` the Orca workspace
    ``displayName`` is also re-set here (via ``orca worktree set``) so it
    shows ``OMS-XXXX`` up front instead of the lowercase branch — the
    initial call in ``cmd_start`` may have lost the same indexing race.

    The startup script is included: it has been verified safely
    re-runnable (idempotent remove-then-recreate of symlinks/dirs). If a
    future project's script isn't idempotent, scope that project out or
    make its script re-runnable — the poller must not special-case it.
    """
    task = ctx.task
    try:
        sessions = agents.active_sessions(task.worktree_path)
    except Exception:
        sessions = []
    if sessions:
        # A session exists (running or done-but-still-listed in Orca): the
        # launch took, so there is nothing to heal.
        return
    # The initial ``set_worktree_display_name`` call in ``cmd_start`` may
    # have lost the same cold-worktree indexing race that stuck the agents;
    # re-fire it now that the worktree should be warm. Best-effort, skipped
    # for prompt-only tasks (no issue key) where the branch name is already
    # the right display name.
    if task.issue is not None:
        gh_issue = task.issue.key if ctx.cfg.provider == "github" else None
        agents.set_worktree_display_name(
            task.worktree_path, task.issue.key, gh_issue
        )
    healed: list[str] = []
    for spec in _specs_for(ctx, task.state):
        record = task.agent_launches.get(spec.label)
        if record is None:
            continue  # never launched (older record pre-dating the tracking)
        age_s = (datetime.now(timezone.utc) - parse_ts(record["launched_at"])).total_seconds()
        if age_s < LAUNCH_WINDOW_S:
            continue  # the detached launcher may still be retrying inside its budget
        if record["attempts"] >= LAUNCH_MAX_ATTEMPTS:
            continue  # give up; the user can still recover manually via pablo relaunch
        fn, args = spec.build(ctx)
        fn(*args)
        task.agent_launches[spec.label] = {
            "launched_at": utcnow(),
            "attempts": record["attempts"] + 1,
        }
        healed.append(f"{spec.label} re-launch #{record['attempts'] + 1}")
        events.append(
            f"{task.branch}: {spec.label} re-launch "
            f"#{record['attempts'] + 1} (no session observed after "
            f"{int(age_s)}s)"
        )
    if healed:
        ctx.store.save(task)


def _poll_task(ctx: TaskCtx, events: list[str]) -> None:
    task = ctx.task
    slug = _repo_slug(ctx.cfg)

    try:
        actual_branch = gitrepo.git(Path(task.worktree_path), "rev-parse", "--abbrev-ref", "HEAD")
    except PabloError:
        actual_branch = task.branch

    # A task that entered draft via /commit-and-pr may not know its PR yet.
    if task.pr_number is None and task.state not in {"in-progress", WAITING}:
        pr = ghpr.pr_for_branch(slug, actual_branch)
        if pr is not None:
            task.pr_number = pr.number
            ctx.store.save(task)

    if task.merged:
        # Merge already detected: only "are the agents done yet → close".
        if not agents.active_sessions(task.worktree_path):
            _close(ctx, events)
        return

    if task.pr_number is not None and ghpr.is_merged(slug, task.pr_number):
        if agents.active_sessions(task.worktree_path):
            task.merged = True
            ctx.store.save(task)
            events.append(
                f"{task.branch}: PR merged, close deferred (agents still running)"
            )
        else:
            _close(ctx, events)
        return

    if task.state == WAITING:
        return  # everything else is deferred while paused

    if task.state in AGENT_LAUNCH_STATES:
        _relaunch_stuck_agents(ctx, events)
    if task.state == IN_PROGRESS:
        return  # pre-draft: nothing else to poll (no PR yet)

    if task.pr_number is None:
        return  # pre-draft (in-progress): nothing to poll

    for check in POLL_CHECKS.get(task.state, []):
        target = check(ctx, slug)
        if target is not None:
            enter_state(ctx, target)
            events.append(f"{task.branch}: → {ctx.task.state}")
            return


def _refresh_display_cache(ctx: TaskCtx) -> None:
    """Persist the Tracker/PR/Agents display fields `pablo tasks` reads,
    so the interactive command never has to fetch them live (listing.py
    falls back to a live fetch only when these are still None, i.e. the
    task has never been polled)."""
    task, cfg = ctx.task, ctx.cfg
    slug = _repo_slug(cfg)

    if task.issue is not None:
        try:
            provider = get_provider(cfg.provider)
            task.cached_tracker_status = provider.issue_status(task.issue.key, cfg)
        except PabloError:
            pass  # keep the last known value rather than blanking it

    if task.pr_number is None:
        task.cached_pr_state = "-"
    else:
        try:
            try:
                actual_branch = gitrepo.git(Path(task.worktree_path), "rev-parse", "--abbrev-ref", "HEAD")
            except PabloError:
                actual_branch = task.branch
            pr = ghpr.pr_for_branch(slug, actual_branch)
            task.cached_pr_state = listing.pr_state_cell(task, pr)
        except PabloError:
            pass

    try:
        sessions = agents.active_sessions(task.worktree_path)
        count, activity = listing.agent_activity_summary(sessions)
        task.cached_agent_count = count
        task.cached_agent_activity = activity
    except Exception:
        pass

    task.cached_at = utcnow()
    ctx.store.save(task)


def poll_project(cfg: ProjectConfig, store: Store) -> list[str]:
    events: list[str] = []
    for task in store.all_tasks(cfg.name):
        try:
            with task_lock(store, cfg.name, task.branch, timeout_s=POLL_LOCK_TIMEOUT_S):
                fresh = store.get(cfg.name, task.branch)
                if fresh is None:
                    continue
                ctx = TaskCtx(task=fresh, cfg=cfg, store=store)
                _poll_task(ctx, events)
                if store.get(cfg.name, task.branch) is not None:
                    _refresh_display_cache(ctx)
        except PabloError as exc:
            events.append(f"{task.branch}: skipped ({exc})")
    return events
