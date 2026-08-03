from datetime import datetime, timezone
from pathlib import Path

import pytest

from pablo import agents, ghpr, gitrepo, poller
from pablo.config import ProjectConfig
from pablo.model import (
    CI_RED,
    DRAFT,
    IN_PROGRESS,
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
        identity="korbeil",
        project_key="PR",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal="qa-failed",
        bot_whitelist=[],
        ci_ignore_checks=[],
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
    monkeypatch.setattr(
        ghpr, "ci_status", lambda slug, pr, ignore_checks=None: stubs["ci"]
    )
    monkeypatch.setattr(ghpr, "is_merged", lambda slug, pr: stubs["merged"])
    monkeypatch.setattr(ghpr, "pr_for_branch", lambda slug, branch: None)
    monkeypatch.setattr(ghpr, "mark_ready", lambda slug, pr: None)
    monkeypatch.setattr(ghpr, "mark_draft", lambda slug, pr: None)
    monkeypatch.setattr(
        ghpr, "ready_anchor",
        lambda slug, pr: datetime(2026, 7, 20, tzinfo=timezone.utc),
    )
    monkeypatch.setattr(ghpr, "fetch_reviews", lambda slug, pr: ("korbeil", []))
    monkeypatch.setattr(
        ghpr, "evaluate_reviews", lambda reviews, **kw: stubs["verdict"]
    )
    monkeypatch.setattr(agents, "active_sessions", lambda wt: stubs["sessions"])
    monkeypatch.setattr(
        agents, "launch", lambda wt, a, p, proj, br: stubs["launched"].append(a) or "t1"
    )
    stubs["startups"] = []
    monkeypatch.setattr(
        agents, "run_startup_script",
        lambda wt, script, proj, br: stubs["startups"].append(str(script)) or "t2",
    )
    stubs["display_names"] = []
    monkeypatch.setattr(
        agents, "set_worktree_display_name",
        lambda wt, name, issue_number=None: stubs["display_names"].append(name),
    )
    monkeypatch.setattr(
        agents, "spawn_watcher", lambda *a, **k: None
    )
    monkeypatch.setattr(
        gitrepo, "remove_worktree",
        lambda repo, path, branch: stubs["removed"].append(branch),
    )

    stubs["issue_status"] = "In Testing"
    stubs["status_calls"] = 0

    class FakeProvider:
        signal_via_status = True

        def failure_signal_events(self, task, cfg):
            return stubs["signal_events"]

        def issue_status(self, key, cfg):
            stubs["status_calls"] += 1
            return stubs["issue_status"]

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
    assert env["stubs"]["launched"] == ["ci-analyst"]


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
    assert env["stubs"]["launched"] == ["ci-analyst"]


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
    assert env["stubs"]["launched"] == ["pr-feedback"]


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


def test_status_transition_triggers_testing_failed(env):
    set_state(
        env, NEEDS_TESTING,
        needs_testing_entered_at="2026-07-22T00:00:00+00:00",
        last_seen_issue_status="In Testing",
    )
    env["stubs"]["issue_status"] = "qa-failed"
    poll(env)
    task = get_task(env)
    assert task.state == TESTING_FAILED
    assert task.last_handled_signal_at is not None
    assert env["stubs"]["launched"] == ["task-feedback"]


def test_status_already_failed_at_entry_does_not_trigger(env):
    # entering needs-testing seeded last_seen with the (stale) signal status
    set_state(
        env, NEEDS_TESTING,
        needs_testing_entered_at="2026-07-22T00:00:00+00:00",
        last_seen_issue_status="qa-failed",
    )
    env["stubs"]["issue_status"] = "qa-failed"
    poll(env)
    assert get_task(env).state == NEEDS_TESTING


def test_status_persisting_does_not_retrigger(env):
    set_state(
        env, NEEDS_TESTING,
        needs_testing_entered_at="2026-07-22T00:00:00+00:00",
        last_seen_issue_status="In Testing",
    )
    env["stubs"]["issue_status"] = "qa-failed"
    poll(env)
    assert get_task(env).state == TESTING_FAILED
    # back to needs-testing after a fix round: status still "qa-failed",
    # last_seen now records it, so mere persistence never re-triggers
    set_state(env, NEEDS_TESTING,
              needs_testing_entered_at="2026-07-23T00:00:00+00:00")
    poll(env)
    assert get_task(env).state == NEEDS_TESTING


def test_changelog_path_still_preferred(env):
    set_state(
        env, NEEDS_TESTING,
        needs_testing_entered_at="2026-07-22T00:00:00+00:00",
        last_seen_issue_status="In Testing",
    )
    env["stubs"]["signal_events"] = [datetime(2026, 7, 23, tzinfo=timezone.utc)]
    env["stubs"]["issue_status"] = "In Testing"
    poll(env)
    task = get_task(env)
    assert task.state == TESTING_FAILED  # via changelog timestamps
    # the one call is the display-cache refresh, not the failure-signal
    # check (which prefers the changelog path and never consults status)
    assert env["stubs"]["status_calls"] == 1


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


def test_poll_persists_display_cache(env, monkeypatch):
    monkeypatch.setattr(
        ghpr, "pr_for_branch",
        lambda slug, branch: ghpr.PrInfo(
            number=7, title="t", state="OPEN", is_draft=True, url="u", merged_at=None
        ),
    )
    env["stubs"]["issue_status"] = "In Progress"
    env["stubs"]["sessions"] = [agents.SessionInfo(handle="a", status="running")]
    poll(env)
    task = get_task(env)
    assert task.cached_tracker_status == "In Progress"
    assert task.cached_pr_state == "📪 draft #7"
    assert task.cached_agent_count == 1
    assert task.cached_agent_activity == "🏃 1"
    assert task.cached_at is not None


def test_poll_display_cache_survives_closed_task(env):
    """A task closed this cycle (merge, agents done) must not be re-saved
    by the cache refresh after `_close` already deleted it."""
    env["stubs"]["merged"] = True
    env["stubs"]["sessions"] = []
    poll(env)
    assert get_task(env) is None


# ----- self-healing for a cold-worktree Orca launch hang (all 4 states) ----


def _fresh_ts(seconds_ago: int) -> str:
    from datetime import timedelta

    return (datetime.now(timezone.utc) - timedelta(seconds=seconds_ago)).isoformat(
        timespec="seconds"
    )


def set_launch(env, label, *, attempts=1, ago=0):
    """Stamp ``agent_launches[label]`` on the current task."""
    task = get_task(env)
    if task.agent_launches is None:
        task.agent_launches = {}
    task.agent_launches[label] = {
        "launched_at": _fresh_ts(ago),
        "attempts": attempts,
    }
    env["store"].save(task)


def test_no_state_change_when_session_present(env):
    env["stubs"]["sessions"] = [agents.SessionInfo(handle="a", status="running")]
    set_state(env, IN_PROGRESS, pr_number=None)
    set_launch(env, "task-analyst", ago=poller.LAUNCH_WINDOW_S + 60)
    poll(env)
    assert env["stubs"]["launched"] == []
    assert env["stubs"]["startups"] == []


def test_caps_at_max_attempts(env):
    set_state(env, IN_PROGRESS, pr_number=None)
    set_launch(
        env, "task-analyst", attempts=poller.LAUNCH_MAX_ATTEMPTS,
        ago=poller.LAUNCH_WINDOW_S + 60,
    )
    poll(env)
    assert env["stubs"]["launched"] == []


@pytest.mark.parametrize("state, label, pr", [
    (IN_PROGRESS, "task-analyst", None),
    (CI_RED, "ci-analyst", 7),
    (REQUEST_CHANGES, "pr-feedback", 7),
    (TESTING_FAILED, "task-feedback", 7),
])
def test_relaunches_missing_agent_past_window(env, state, label, pr):
    set_state(env, state, pr_number=pr, state_entered_at=_fresh_ts(poller.LAUNCH_WINDOW_S + 70))
    set_launch(env, label, ago=poller.LAUNCH_WINDOW_S + 60)
    poll(env)
    assert env["stubs"]["launched"] == [label]
    rec = get_task(env).agent_launches[label]
    assert rec["attempts"] == 2
    assert env["stubs"]["startups"] == []


def test_in_progress_relouches_startup_past_window(env):
    from dataclasses import replace

    env["cfg"] = replace(env["cfg"], startup_script=Path("/setup.sh"))
    set_state(env, IN_PROGRESS, pr_number=None)
    set_launch(env, "task-analyst", ago=poller.LAUNCH_WINDOW_S + 60)
    set_launch(env, "startup-script", ago=poller.LAUNCH_WINDOW_S + 60)
    poll(env)
    assert env["stubs"]["launched"] == ["task-analyst"]
    assert env["stubs"]["startups"] == ["/setup.sh"]


def test_no_relouch_within_launch_window(env):
    set_state(env, CI_RED, pr_number=7, state_entered_at=_fresh_ts(20))
    set_launch(env, "ci-analyst", ago=10)
    poll(env)
    assert env["stubs"]["launched"] == []


def test_display_name_re_set_during_self_heal(env):
    """When the poller self-heals a stuck in-progress task (no session, past
    the launch window), it also re-fires ``set_worktree_display_name`` so
    the Orca UI shows the issue key (e.g. ``OMS-6393``) instead of the
    lowercase branch."""
    set_state(env, IN_PROGRESS, pr_number=None)
    set_launch(env, "task-analyst", ago=poller.LAUNCH_WINDOW_S + 60)
    poll(env)
    assert env["stubs"]["display_names"] == ["1"]  # issue.key for this fixture


def test_display_name_not_re_set_when_sessions_exist(env):
    """When agents are running fine (sessions present), the poller skips
    self-heal entirely — no display name re-fire either."""
    env["stubs"]["sessions"] = [agents.SessionInfo(handle="a", status="running")]
    set_state(env, IN_PROGRESS, pr_number=None)
    set_launch(env, "task-analyst", ago=poller.LAUNCH_WINDOW_S + 60)
    poll(env)
    assert env["stubs"]["display_names"] == []


def test_skip_ci_ignores_red_ci(env):
    set_state(env, WAITING_REVIEW, ci_ignored=True)
    env["stubs"]["ci"] = "red"
    env["stubs"]["verdict"] = None
    poll(env)
    assert get_task(env).state == WAITING_REVIEW
    assert env["stubs"]["launched"] == []


def test_ci_ignored_reset_on_draft(env, monkeypatch):
    from pablo import states as states_mod

    task = get_task(env)
    task.ci_ignored = True
    task.state = WAITING_REVIEW
    env["store"].save(task)
    cfg = env["cfg"]
    from pablo.states import TaskCtx

    ctx = TaskCtx(task=task, cfg=cfg, store=env["store"])
    states_mod.enter_state(ctx, DRAFT)
    assert task.ci_ignored is False
