import json
import subprocess
from pathlib import Path

import pytest

from pablo import agents, cli, ghpr, gitrepo
from pablo.config import ProjectConfig
from pablo.model import DRAFT, IN_PROGRESS, REQUEST_CHANGES, WAITING, Issue, Task
from pablo.store import Store


def make_cfg(tmp_path: Path) -> ProjectConfig:
    return ProjectConfig(
        name="wallet-kit",
        type="open-source",
        repo_path=tmp_path / "repo",
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="github",
        identity="korbeil",
        project_key="WK",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
    )


@pytest.fixture
def env(tmp_path, monkeypatch):
    monkeypatch.setenv("PABLO_STATE_DIR", str(tmp_path / "state"))
    cfg = make_cfg(tmp_path)
    cfg.repo_path.mkdir(parents=True)
    wt = tmp_path / "wt" / "wk-45"
    wt.mkdir(parents=True)
    subprocess.run(["git", "init", "-q", str(wt)], check=True)
    task = Task(
        project="wallet-kit",
        branch="wk-45",
        worktree_path=wt,
        state=IN_PROGRESS,
        pr_number=7,
        issue=Issue(provider="github", key="45", url="u", title="T", project_key="WK"),
    )
    Store().save(task)
    monkeypatch.setattr(cli, "load_projects", lambda: {"wallet-kit": cfg})
    monkeypatch.chdir(wt)
    return {"cfg": cfg, "wt": wt, "store": lambda: Store()}


def test_state_forces_with_shared_handler(env, monkeypatch, capsys):
    launched = []
    monkeypatch.setattr(
        agents, "launch", lambda wt, agent, prompt: launched.append(agent) or "t1"
    )
    monkeypatch.setattr(agents, "spawn_watcher", lambda *a, **k: None)
    rc = cli.main(["state", REQUEST_CHANGES])
    assert rc == 0
    assert launched == ["pr-review-planner"]
    assert env["store"]().get("wallet-kit", "wk-45").state == REQUEST_CHANGES


def test_state_no_trigger_skips_actions(env, monkeypatch):
    launched = []
    monkeypatch.setattr(
        agents, "launch", lambda wt, agent, prompt: launched.append(agent) or "t1"
    )
    rc = cli.main(["state", REQUEST_CHANGES, "--no-trigger"])
    assert rc == 0
    assert launched == []


def test_state_outside_worktree_fails(env, monkeypatch, tmp_path):
    monkeypatch.chdir(tmp_path)
    assert cli.main(["state", DRAFT]) == 1


def test_waiting_toggle_roundtrip(env, capsys):
    assert cli.main(["waiting"]) == 0
    assert env["store"]().get("wallet-kit", "wk-45").state == WAITING
    assert cli.main(["waiting"]) == 0
    task = env["store"]().get("wallet-kit", "wk-45")
    assert task.state == IN_PROGRESS
    assert task.task_analyst_ran is True  # restore ran the on-enter (sets flag)


def test_waiting_refused_from_request_changes(env, monkeypatch):
    store = env["store"]()
    task = store.get("wallet-kit", "wk-45")
    task.state = REQUEST_CHANGES
    store.save(task)
    assert cli.main(["waiting"]) == 1


def test_close_refuses_while_agents_active(env, monkeypatch, capsys):
    monkeypatch.setattr(
        agents, "active_sessions",
        lambda wt: [agents.SessionInfo(handle="a", status="running")],
    )
    rc = cli.main(["close"])
    assert rc == 1
    assert "agent" in capsys.readouterr().err


def test_close_removes_worktree_and_record(env, monkeypatch):
    monkeypatch.setattr(agents, "active_sessions", lambda wt: [])
    removed = []
    monkeypatch.setattr(
        gitrepo, "remove_worktree",
        lambda repo, path, branch: removed.append((path, branch)),
    )
    rc = cli.main(["close", "--yes"])
    assert rc == 0
    assert removed == [(env["wt"], "wk-45")]
    assert env["store"]().get("wallet-kit", "wk-45") is None


def test_precommit_check_allowed_states(env, capsys):
    rc = cli.main(["precommit-check", "--json"])
    assert rc == 0
    data = json.loads(capsys.readouterr().out)
    assert data == {
        "project": "wallet-kit",
        "branch": "wk-45",
        "state": IN_PROGRESS,
        "allowed": True,
    }


def test_precommit_check_disallowed_state(env, capsys):
    store = env["store"]()
    task = store.get("wallet-kit", "wk-45")
    task.state = WAITING
    store.save(task)
    rc = cli.main(["precommit-check", "--json"])
    assert rc == 0
    assert json.loads(capsys.readouterr().out)["allowed"] is False


def test_precommit_check_refuses_non_task_dir(env, monkeypatch, tmp_path, capsys):
    monkeypatch.chdir(tmp_path)
    assert cli.main(["precommit-check", "--json"]) == 2


def test_task_current_dumps_record(env, capsys):
    rc = cli.main(["task", "current", "--json"])
    assert rc == 0
    data = json.loads(capsys.readouterr().out)
    assert data["branch"] == "wk-45"
    assert data["repo_path"] == str(env["cfg"].repo_path)


def test_watch_agent_drafts_pr_when_state_matches(env, monkeypatch):
    store = env["store"]()
    task = store.get("wallet-kit", "wk-45")
    task.state = REQUEST_CHANGES
    store.save(task)
    monkeypatch.setattr(agents, "wait_for_handle", lambda handle, **k: None)
    drafted = []
    monkeypatch.setattr(cli, "_repo_slug", lambda cfg: "acme/wallet-kit")
    monkeypatch.setattr(ghpr, "mark_draft", lambda slug, pr: drafted.append(pr))
    rc = cli.main(
        ["watch-agent", "--project", "wallet-kit", "--branch", "wk-45",
         "--handle", "t1", "--then", "pr-draft", "--expect-state", REQUEST_CHANGES]
    )
    assert rc == 0
    assert drafted == [7]


def test_watch_agent_skips_when_state_moved_on(env, monkeypatch):
    monkeypatch.setattr(agents, "wait_for_handle", lambda handle, **k: None)
    drafted = []
    monkeypatch.setattr(ghpr, "mark_draft", lambda slug, pr: drafted.append(pr))
    rc = cli.main(
        ["watch-agent", "--project", "wallet-kit", "--branch", "wk-45",
         "--handle", "t1", "--then", "pr-draft", "--expect-state", REQUEST_CHANGES]
    )
    assert rc == 0
    assert drafted == []  # task is in-progress, not request-changes anymore
