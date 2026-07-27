from pathlib import Path

import pytest

from pablo import PabloError, agents, ghpr, states
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
    Issue,
    Task,
)
from pablo.store import Store


@pytest.fixture
def ctx(tmp_path: Path, monkeypatch):
    cfg = ProjectConfig(
        name="wallet-kit",
        type="open-source",
        repo_path=tmp_path / "repo",
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="github",
        identity="user",
        project_key="WK",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal="qa-failed",
        bot_whitelist=[],
        ci_ignore_checks=[],
    )
    task = Task(
        project="wallet-kit",
        branch="wk-45",
        worktree_path=tmp_path / "wt" / "wk-45",
        state=IN_PROGRESS,
        pr_number=7,
        issue=Issue(provider="github", key="45", url="u", title="T", project_key="WK"),
    )
    store = Store(root=tmp_path / "state")
    calls = {"launch": [], "watch": [], "ready": [], "draft": []}
    monkeypatch.setattr(
        agents, "launch",
        lambda wt, agent, prompt: calls["launch"].append((agent, prompt)) or "term_1",
    )
    monkeypatch.setattr(
        agents, "spawn_watcher",
        lambda project, branch, handle, then, expect_state=None: calls["watch"].append(
            (handle, then)
        ),
    )
    monkeypatch.setattr(
        ghpr, "mark_ready", lambda slug, pr: calls["ready"].append(pr)
    )
    monkeypatch.setattr(
        ghpr, "mark_draft", lambda slug, pr: calls["draft"].append(pr)
    )
    monkeypatch.setattr(states, "_repo_slug", lambda cfg: "acme/wallet-kit")
    context = states.TaskCtx(task=task, cfg=cfg, store=store)
    context.calls = calls
    return context


def test_enter_in_progress_runs_analyst_once(ctx):
    states.enter_state(ctx, IN_PROGRESS)
    assert len(ctx.calls["launch"]) == 1
    assert ctx.calls["launch"][0][0] == "task-analyst"
    assert ctx.task.task_analyst_ran is True
    states.enter_state(ctx, IN_PROGRESS)  # run-once flag respected
    assert len(ctx.calls["launch"]) == 1


def test_waiting_saves_and_restores_previous_state(ctx):
    ctx.task.state = WAITING_REVIEW
    states.toggle_waiting(ctx)
    assert ctx.task.state == WAITING
    assert ctx.task.state_before_waiting == WAITING_REVIEW
    states.toggle_waiting(ctx)
    assert ctx.task.state == WAITING_REVIEW
    assert ctx.task.state_before_waiting is None


def test_waiting_forbidden_from_request_changes_and_testing_failed(ctx):
    for forbidden in (REQUEST_CHANGES, TESTING_FAILED):
        ctx.task.state = forbidden
        with pytest.raises(PabloError, match="impossible"):
            states.toggle_waiting(ctx)
        with pytest.raises(PabloError, match="impossible"):
            states.enter_state(ctx, WAITING)


def test_no_trigger_skips_actions_except_waiting(ctx):
    states.enter_state(ctx, REQUEST_CHANGES, trigger=False)
    assert ctx.calls["launch"] == []
    assert ctx.calls["draft"] == []
    # --no-trigger is ignored for waiting: saving the previous state IS the
    # on-enter action, without it there'd be nothing to restore to.
    ctx.task.state = DRAFT
    states.enter_state(ctx, WAITING, trigger=False)
    assert ctx.task.state_before_waiting == DRAFT


def test_ready_to_review_chains_to_waiting_review_and_marks_ready(ctx):
    ctx.task.state = DRAFT
    states.enter_state(ctx, READY_TO_REVIEW)
    assert ctx.calls["ready"] == [7]
    assert ctx.task.state == WAITING_REVIEW


def test_request_changes_launches_planner_then_drafts_pr(ctx):
    ctx.task.state = WAITING_REVIEW
    states.enter_state(ctx, REQUEST_CHANGES)
    assert ctx.calls["launch"][0][0] == "pr-feedback"
    assert ctx.calls["watch"] == [("term_1", "pr-draft")]
    assert ctx.calls["draft"] == []  # drafting happens after the agent finishes


def test_ci_red_runs_analyst(ctx):
    ctx.task.state = DRAFT
    states.enter_state(ctx, CI_RED)
    assert ctx.calls["launch"][0][0] == "ci-analyst"
    assert ctx.calls["watch"] == []  # no pr-draft flip — the PR is untouched
    assert ctx.calls["draft"] == []
    # no run-once guard: a fresh ci-red entry always re-runs the analyst
    states.enter_state(ctx, READY_TO_REVIEW)
    states.enter_state(ctx, CI_RED)
    assert len(ctx.calls["launch"]) == 2
    assert ctx.calls["launch"][1][0] == "ci-analyst"


def test_testing_failed_launches_feedback_then_drafts_pr(ctx):
    ctx.task.state = NEEDS_TESTING
    states.enter_state(ctx, TESTING_FAILED)
    assert ctx.calls["launch"][0][0] == "task-feedback"
    assert ctx.calls["watch"] == [("term_1", "pr-draft")]


def test_needs_testing_stamps_baseline(ctx):
    ctx.task.state = WAITING_REVIEW
    states.enter_state(ctx, NEEDS_TESTING)
    assert ctx.task.needs_testing_entered_at is not None


def test_needs_testing_seeds_last_seen_status(ctx, monkeypatch):
    monkeypatch.setattr(states, "_current_issue_status", lambda c: "A FIX")
    ctx.task.state = WAITING_REVIEW
    states.enter_state(ctx, NEEDS_TESTING)
    # a stale failure status present at entry is recorded, so the poller's
    # observed-transition fallback won't fire on it
    assert ctx.task.last_seen_issue_status == "A FIX"


def test_needs_testing_restore_from_waiting_keeps_baseline(ctx):
    ctx.task.state = WAITING_REVIEW
    states.enter_state(ctx, NEEDS_TESTING)
    baseline = ctx.task.needs_testing_entered_at
    states.toggle_waiting(ctx)
    states.toggle_waiting(ctx)  # restore
    assert ctx.task.state == NEEDS_TESTING
    # a failure signal applied during the pause must still be newer than the
    # baseline — restoring must not move it forward
    assert ctx.task.needs_testing_entered_at == baseline


def test_enter_state_persists(ctx):
    states.enter_state(ctx, DRAFT)
    saved = ctx.store.get("wallet-kit", "wk-45")
    assert saved is not None
    assert saved.state == DRAFT


def test_unknown_state_raises(ctx):
    with pytest.raises(PabloError, match="unknown state"):
        states.enter_state(ctx, "nonsense")


def test_commit_allowed_set():
    assert states.COMMIT_ALLOWED_FROM == {
        IN_PROGRESS,
        CI_RED,
        REQUEST_CHANGES,
        TESTING_FAILED,
    }


def test_analyst_prompt_for_prompt_task(ctx):
    ctx.task.issue = None
    ctx.task.prompt = "fix callback verification in the webhook handler"
    ctx.task.task_analyst_ran = False
    states.enter_state(ctx, IN_PROGRESS)
    prompt = ctx.calls["launch"][0][1]
    assert "fix callback verification in the webhook handler" in prompt
