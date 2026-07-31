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
    handle = agents._do_launch_agent(tmp_path, "task-analyst", "Analyze issue #45")
    assert handle == "term_123"
    argv = calls[0]
    assert argv[:3] == ["orca", "terminal", "create"]
    assert f"path:{tmp_path}" in argv
    assert "pablo:task-analyst" in argv
    assert "--focus" not in argv
    command = argv[argv.index("--command") + 1]
    assert command.startswith("opencode ")
    assert "--agent task-analyst" in command
    assert "--prompt" in command
    assert "Analyze issue #45" in command


def test_launch_falls_back_headless_when_orca_refuses(monkeypatch, tmp_path, agents_dir):
    launched = {}
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return orca_err("selector_not_found")

    class FakeProc:
        pid = 4242

    def fake_popen(argv, **kwargs):
        launched["argv"] = argv
        return FakeProc()

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    monkeypatch.setattr(agents.subprocess, "Popen", fake_popen)
    monkeypatch.setattr(agents.time, "sleep", lambda s: None)
    handle = agents._do_launch_agent(tmp_path, "task-analyst", "hello")
    assert handle == "pid:4242"
    assert launched["argv"][:3] == ["opencode", "run", "--agent"]
    assert "--dir" in launched["argv"]
    assert len(calls) == 1  # single `terminal create` call, then fall back
    fallback_log = (agents_dir / "logs" / "orca-fallback.log").read_text()
    assert "task-analyst" in fallback_log
    assert "selector_not_found" in fallback_log


def test_launch_lock_namespacing_per_worktree(monkeypatch, tmp_path, agents_dir):
    """Per-worktree ``flock``: same worktree reuses one lockfile; different
    worktrees get independent ones — so concurrent launchers on the same
    cold path serialise without blocking unrelated worktrees."""
    wt_a = tmp_path / "repo" / "wt-a"
    wt_b = tmp_path / "repo" / "wt-b"
    wt_a.mkdir(parents=True)
    wt_b.mkdir(parents=True)
    monkeypatch.setattr(agents, "run_cli", lambda *a, **k: orca_ok({"handle": "term_x"}))
    monkeypatch.setattr(agents.time, "sleep", lambda s: None)
    with agents._acquire_launch_lock(wt_a):
        pass
    with agents._acquire_launch_lock(wt_b):
        pass
    with agents._acquire_launch_lock(wt_a):  # reuse, not a new lockfile
        pass
    names = sorted(p.name for p in (agents_dir / "locks").iterdir())
    assert len(names) == 2  # one per worktree, not one per acquire


def test_run_startup_script_command_and_keepalive(monkeypatch, tmp_path):
    """``_do_run_startup_script`` opens an Orca terminal running the script,
    wrapped with ``; exec bash`` so the tab drops into an interactive shell
    at the worktree root when the script finishes (instead of auto-closing
    on PTY EOF)."""
    script = tmp_path / "setup.sh"
    script.write_text("#!/bin/bash\necho hi\n")
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return orca_ok({"terminal": {"handle": "term_s"}})

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    handle = agents._do_run_startup_script(tmp_path, script)
    assert handle == "term_s"
    argv = calls[0]
    assert argv[:3] == ["orca", "terminal", "create"]
    assert "pablo:startup-script" in argv
    command = argv[argv.index("--command") + 1]
    assert command.startswith("bash ")
    assert str(script) in command
    assert command.endswith("; exec bash")  # keep tab open after the script exits


def test_launch_detaches_and_returns_immediately(monkeypatch, tmp_path):
    spawned = {}

    class FakeProc:
        pid = 777

    def fake_popen(argv, **kwargs):
        spawned["argv"] = argv
        spawned["kwargs"] = kwargs
        return FakeProc()

    def fail_run_cli(*a, **k):
        raise AssertionError("launch() must not call orca/run_cli directly")

    monkeypatch.setattr(agents.subprocess, "Popen", fake_popen)
    monkeypatch.setattr(agents, "run_cli", fail_run_cli)
    handle = agents.launch(tmp_path, "task-analyst", "hello")
    assert handle == "pid:777"
    assert spawned["argv"][:3] == [agents.sys.executable, "-m", "pablo.cli"]
    assert "internal-launch-agent" in spawned["argv"]
    assert "--worktree" in spawned["argv"]
    assert str(tmp_path) in spawned["argv"]
    assert "--agent" in spawned["argv"]
    assert "task-analyst" in spawned["argv"]
    assert "--prompt" in spawned["argv"]
    assert "hello" in spawned["argv"]
    assert spawned["kwargs"].get("start_new_session") is True
    assert spawned["kwargs"].get("stdin") is agents.subprocess.DEVNULL
    assert spawned["kwargs"].get("stdout") is agents.subprocess.DEVNULL
    assert spawned["kwargs"].get("stderr") is agents.subprocess.DEVNULL


def test_run_startup_script_detaches_and_returns_immediately(monkeypatch, tmp_path):
    spawned = {}
    script = tmp_path / "setup.sh"

    class FakeProc:
        pid = 888

    def fake_popen(argv, **kwargs):
        spawned["argv"] = argv
        spawned["kwargs"] = kwargs
        return FakeProc()

    def fail_run_cli(*a, **k):
        raise AssertionError("run_startup_script() must not call orca/run_cli directly")

    monkeypatch.setattr(agents.subprocess, "Popen", fake_popen)
    monkeypatch.setattr(agents, "run_cli", fail_run_cli)
    handle = agents.run_startup_script(tmp_path, script)
    assert handle == "pid:888"
    assert "internal-run-startup-script" in spawned["argv"]
    assert "--worktree" in spawned["argv"]
    assert str(tmp_path) in spawned["argv"]
    assert "--script" in spawned["argv"]
    assert str(script) in spawned["argv"]
    assert spawned["kwargs"].get("start_new_session") is True


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


def test_bulk_active_sessions_one_orca_call_for_many_worktrees(monkeypatch, tmp_path):
    wt_a = tmp_path / "a"
    wt_b = tmp_path / "b"
    ps = {
        "worktrees": [
            {"path": str(wt_a), "agents": [{"paneKey": "a1", "state": "working"}]},
            {"path": str(wt_b), "agents": [{"paneKey": "b1", "state": "awaiting-input"}]},
            {"path": "/elsewhere", "agents": [{"paneKey": "c1", "state": "working"}]},
        ]
    }
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return orca_ok(ps)

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    result = agents.bulk_active_sessions([wt_a, wt_b])
    assert len(calls) == 1  # one orca call total, not one per worktree
    assert [(s.handle, s.status) for s in result[wt_a]] == [("a1", "running")]
    assert [(s.handle, s.status) for s in result[wt_b]] == [("b1", "waiting")]


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


def test_set_worktree_display_name_calls_orca(monkeypatch, tmp_path):
    """``set_worktree_display_name`` issues ``orca worktree set`` with the
    ``--display-name`` flag so the Orca UI shows e.g. ``OMS-6407`` instead
    of the lowercase branch that Orca auto-derives from the path. Passes
    ``--issue null`` by default so Orca doesn't auto-detect a wrong PR."""
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return orca_ok({})

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    agents.set_worktree_display_name(tmp_path, "OMS-6407")
    argv = calls[0]
    assert argv[:3] == ["orca", "worktree", "set"]
    assert f"path:{tmp_path}" in argv
    assert "OMS-6407" in argv
    assert "--display-name" in argv
    assert "--issue" in argv
    assert argv[argv.index("--issue") + 1] == "null"


def test_set_worktree_display_name_links_github_issue(monkeypatch, tmp_path):
    """Passing ``issue_number`` links the GitHub issue instead of null."""
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return orca_ok({})

    monkeypatch.setattr(agents, "run_cli", fake_run_cli)
    agents.set_worktree_display_name(tmp_path, "OMS-6407", "273")
    argv = calls[0]
    assert argv[argv.index("--issue") + 1] == "273"


def test_set_worktree_display_name_silently_ignores_failure(monkeypatch, tmp_path):
    """A best-effort call: Orca unavailable or worktree not yet indexed must
    not raise — Orca's own self-correction remains the fallback."""
    monkeypatch.setattr(agents, "run_cli", lambda *a, **k: orca_err("selector_not_found"))
    agents.set_worktree_display_name(tmp_path, "OMS-6407")  # must not raise


def test_orca_reason_embeds_raw_stdout_on_empty(monkeypatch, tmp_path):
    """The opaque empty-stdout failure mode (observed 2026-07-29 on
    oms-6421/acme-8266 as a bare ``JSONDecodeError: Expecting value…``)
    must surface the raw stdout orca returned so the log is actionable."""
    def fake_empty(_argv, *, check=True, timeout=None):
        return ""
    monkeypatch.setattr(agents, "run_cli", fake_empty)
    _, reason = agents._orca(["worktree", "list"])
    assert "raw_stdout=''" in reason
    assert "JSONDecodeError" in reason
