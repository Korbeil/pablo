from datetime import datetime, timezone
from pathlib import Path

import pytest

from pablo import agents, ghpr, gitrepo, poller
from pablo.config import ProjectConfig
from pablo.model import (
    CI_RED,
    DRAFT,
    NEEDS_TESTING,
    REQUEST_CHANGES,
    TESTING_FAILED,
    WAITING,
    WAITING_REVIEW,
    Issue,
    Task,
)
from pablo.store import Store


def make_cfg(tmp_path: Path) -> ProjectConfig:
    return ProjectConfig(
        name="proj",
        type="work",
        repo_path=tmp_path / "repo",
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="github",
        identity="user",
        project_key="PR",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal="qa-failed",
        bot_whitelist=[],
    )


@pytest.fixture
def env(tmp_path, monkeypatch):
    cfg = make_cfg(tmp_path)
    store = Store(root=tmp_path / "state")
    task = Task(
        project="proj",
        branch="pr-1",
        worktree_path=tmp_path / "wt" / "pr-1",
        state=DRAFT,
        pr_number=7,
        issue=Issue(provider="github", key="1", url="u", title="T", project_key="PR"),
    )
    store.save(task)
    stubs = {
        "ci": "pending",
        "merged": False,
        "verdict": None,
        "sessions": [],
        "signal_events": [],
        "removed": [],
        "launched": [],
    }
    monkeypatch.setattr(poller, "_repo_slug", lambda cfg: "acme/proj")
    from pablo import states

    monkeypatch.setattr(states, "_repo_slug", lambda cfg: "acme/proj")
    monkeypatch.setattr(ghpr, "ci_status", lambda slug, pr: stubs["ci"])
    monkeypatch.setattr(ghpr, "is_merged", lambda slug, pr: stubs["merged"])
    monkeypatch.setattr(ghpr, "mark_ready", lambda slug, pr: None)
    monkeypatch.setattr(ghpr, "mark_draft", lambda slug, pr: None)
    monkeypatch.setattr(
        ghpr, "ready_anchor",
        lambda slug, pr: datetime(2026, 7, 20, tzinfo=timezone.utc),
    )
    monkeypatch.setattr(ghpr, "fetch_reviews", lambda slug, pr: ("user", []))
    monkeypatch.setattr(
        ghpr, "evaluate_reviews", lambda reviews, **kw: stubs["verdict"]
    )
    monkeypatch.setattr(agents, "active_sessions", lambda wt: stubs["sessions"])
    monkeypatch.setattr(
        agents, "launch", lambda wt, a, p: stubs["launched"].append(a) or "t1"
    )
    monkeypatch.setattr(
        agents, "spawn_watcher", lambda *a, **k: None
    )
    monkeypatch.setattr(
        gitrepo, "remove_worktree",
        lambda repo, path, branch: stubs["removed"].append(branch),
    )

    class FakeProvider:
        def failure_signal_events(self, task, cfg):
            return stubs["signal_events"]

    monkeypatch.setattr(poller, "get_provider", lambda name: FakeProvider())
    return {"cfg": cfg, "store": store, "stubs": stubs}


def poll(env):
    return poller.poll_project(env["cfg"], env["store"])


def get_task(env):
    return env["store"].get("proj", "pr-1")


def set_state(env, state, **fields):
    task = get_task(env)
    task.state = state
    for key, value in fields.items():
        setattr(task, key, value)
    env["store"].save(task)


def test_draft_to_ci_red(env):
    env["stubs"]["ci"] = "red"
    poll(env)
    assert get_task(env).state == CI_RED


def test_draft_pending_stays(env):
    env["stubs"]["ci"] = "pending"
    poll(env)
    assert get_task(env).state == DRAFT


def test_ci_red_to_ready_chains_to_waiting_review(env):
    set_state(env, CI_RED)
    env["stubs"]["ci"] = "green"
    poll(env)
    assert get_task(env).state == WAITING_REVIEW


def test_waiting_review_ci_red_takes_priority(env):
    set_state(env, WAITING_REVIEW)
    env["stubs"]["ci"] = "red"
    env["stubs"]["verdict"] = "approved"
    poll(env)
    assert get_task(env).state == CI_RED


def test_waiting_review_approved_to_needs_testing(env):
    set_state(env, WAITING_REVIEW)
    env["stubs"]["ci"] = "green"
    env["stubs"]["verdict"] = "approved"
    poll(env)
    task = get_task(env)
    assert task.state == NEEDS_TESTING
    assert task.needs_testing_entered_at is not None


def test_waiting_review_changes_to_request_changes(env):
    set_state(env, WAITING_REVIEW)
    env["stubs"]["ci"] = "green"
    env["stubs"]["verdict"] = "changes"
    poll(env)
    assert get_task(env).state == REQUEST_CHANGES
    assert env["stubs"]["launched"] == ["pr-review-planner"]


def test_needs_testing_signal_baseline_and_handled_dedupe(env):
    set_state(
        env, NEEDS_TESTING,
        needs_testing_entered_at="2026-07-22T00:00:00+00:00",
    )
    old = datetime(2026, 7, 21, tzinfo=timezone.utc)  # before baseline
    env["stubs"]["signal_events"] = [old]
    poll(env)
    assert get_task(env).state == NEEDS_TESTING  # old label never triggers

    new = datetime(2026, 7, 23, tzinfo=timezone.utc)
    env["stubs"]["signal_events"] = [old, new]
    poll(env)
    task = get_task(env)
    assert task.state == TESTING_FAILED
    assert task.last_handled_signal_at == new.isoformat()
    assert env["stubs"]["launched"] == ["task-feedback"]

    # continued presence of the same event does not re-trigger
    set_state(env, NEEDS_TESTING, needs_testing_entered_at="2026-07-22T00:00:00+00:00")
    poll(env)
    assert get_task(env).state == NEEDS_TESTING


def test_ci_red_during_needs_testing_is_accepted_gap(env):
    set_state(env, NEEDS_TESTING, needs_testing_entered_at="2026-07-22T00:00:00+00:00")
    env["stubs"]["ci"] = "red"
    poll(env)
    assert get_task(env).state == NEEDS_TESTING


def test_waiting_skips_transitions_but_not_merge(env):
    set_state(env, WAITING, state_before_waiting=WAITING_REVIEW)
    env["stubs"]["ci"] = "red"
    env["stubs"]["verdict"] = "changes"
    poll(env)
    assert get_task(env).state == WAITING  # no transitions evaluated

    env["stubs"]["merged"] = True
    poll(env)
    assert get_task(env) is None  # merge detection still ran → closed
    assert env["stubs"]["removed"] == ["pr-1"]


def test_merge_with_active_agents_defers_close(env):
    env["stubs"]["merged"] = True
    env["stubs"]["sessions"] = [agents.SessionInfo(handle="a", status="running")]
    poll(env)
    task = get_task(env)
    assert task is not None
    assert task.merged is True  # listing shows ✅ merged

    # once merged, no other transition evaluation happens
    env["stubs"]["ci"] = "red"
    poll(env)
    assert get_task(env).state == DRAFT

    env["stubs"]["sessions"] = []
    poll(env)
    assert get_task(env) is None
    assert env["stubs"]["removed"] == ["pr-1"]


def test_pr_number_discovered_for_draft(env, monkeypatch):
    set_state(env, DRAFT, pr_number=None)
    monkeypatch.setattr(
        ghpr, "pr_for_branch",
        lambda slug, branch: ghpr.PrInfo(
            number=9, title="t", state="OPEN", is_draft=True, url="u", merged_at=None
        ),
    )
    env["stubs"]["ci"] = "pending"
    poll(env)
    assert get_task(env).pr_number == 9


def test_in_progress_task_untouched(env):
    set_state(env, "in-progress", pr_number=None)
    events = poll(env)
    assert get_task(env).state == "in-progress"
