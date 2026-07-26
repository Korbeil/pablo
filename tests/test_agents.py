import json
import os
from pathlib import Path

import pytest

from pablo import agents


@pytest.fixture(autouse=True)
def agents_dir(tmp_path, monkeypatch):
    monkeypatch.setenv("PABLO_AGENTS_DIR", str(tmp_path / "agents"))
    return tmp_path / "agents"


def orca_ok(payload: dict) -> str:
    return json.dumps({"id": "x", "ok": True, "result": payload})


def orca_err(code: str) -> str:
    return json.dumps({"id": "x", "ok": False, "error": {"code": code, "message": code}})


def test_orca_launch_builds_command(monkeypatch, tmp_path):
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return orca_ok({"handle": "term_123"})

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    handle = agents.launch(tmp_path, "task-analyst", "Analyze issue #45")
    assert handle == "term_123"
    argv = calls[0]
    assert argv[:3] == ["orca", "terminal", "create"]
    assert f"path:{tmp_path}" in argv
    assert "pablo:task-analyst" in argv
    command = argv[argv.index("--command") + 1]
    assert command.startswith("opencode run --agent task-analyst ")
    assert "Analyze issue #45" in command


def test_launch_falls_back_headless_when_orca_refuses(monkeypatch, tmp_path):
    launched = {}

    def fake_run_cli(argv, *, check=True, timeout=None):
        return orca_err("selector_not_found")

    class FakeProc:
        pid = 4242

    def fake_popen(argv, **kwargs):
        launched["argv"] = argv
        return FakeProc()

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    monkeypatch.setattr(agents.subprocess, "Popen", fake_popen)
    handle = agents.launch(tmp_path, "task-analyst", "hello")
    assert handle == "pid:4242"
    assert launched["argv"][:3] == ["opencode", "run", "--agent"]
    assert "--dir" in launched["argv"]


def test_orca_sessions_map_agent_states(monkeypatch, tmp_path):
    ps = {
        "worktrees": [
            {
                "path": str(tmp_path),
                "agents": [
                    {"paneKey": "a", "state": "working"},
                    {"paneKey": "b", "state": "awaiting-input"},
                ],
            },
            {"path": "/elsewhere", "agents": [{"paneKey": "c", "state": "working"}]},
        ]
    }
    monkeypatch.setattr(agents, "run_cli", lambda argv, *, check=True, timeout=None: orca_ok(ps))
    sessions = agents.active_sessions(tmp_path)
    assert [(s.handle, s.status) for s in sessions] == [
        ("a", "running"),
        ("b", "waiting"),
    ]


def test_headless_sessions_from_pidfiles(monkeypatch, tmp_path, agents_dir):
    # Orca unreachable → fall back to pidfile scan
    monkeypatch.setattr(agents, "run_cli", lambda argv, *, check=True, timeout=None: orca_err("down"))
    agents_dir.mkdir(parents=True)
    alive = os.getpid()
    (agents_dir / f"{alive}.json").write_text(
        json.dumps({"pid": alive, "worktree": str(tmp_path), "agent": "task-analyst"})
    )
    (agents_dir / "999999.json").write_text(
        json.dumps({"pid": 999999, "worktree": str(tmp_path), "agent": "task-analyst"})
    )
    sessions = agents.active_sessions(tmp_path)
    assert [(s.handle, s.status) for s in sessions] == [(f"pid:{alive}", "running")]
    assert not (agents_dir / "999999.json").exists()  # dead pidfile pruned


def test_wait_for_pid_handle_returns_when_dead(monkeypatch):
    monkeypatch.setattr(agents.time, "sleep", lambda s: None)
    agents.wait_for_handle("pid:999999", timeout_s=1)  # dead pid → returns immediately


def test_spawn_watcher_detaches(monkeypatch, tmp_path):
    spawned = {}

    class FakeProc:
        pid = 1

    def fake_popen(argv, **kwargs):
        spawned["argv"] = argv
        spawned["kwargs"] = kwargs
        return FakeProc()

    monkeypatch.setattr(agents.subprocess, "Popen", fake_popen)
    agents.spawn_watcher("proj", "br-1", "term_9", "pr-draft")
    assert "watch-agent" in spawned["argv"]
    assert spawned["kwargs"].get("start_new_session") is True
