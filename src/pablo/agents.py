"""Launching OpenCode agents on worktrees and querying their activity.

Primary path is the Orca CLI (spec, "Implementation architecture"):

    orca terminal create --worktree path:<wt> --title pablo:<agent> \
        --command "opencode run --agent <agent> <prompt>" --json

Activity is read from ``orca worktree ps --json`` (per-worktree ``agents[]``
with a ``state`` field). Orca only resolves ``path:`` selectors for
worktrees of repos registered in Orca (``orca repo add``); when it refuses
(e.g. ``selector_not_found``) or is unreachable, PABLO falls back to a
headless ``opencode run`` tracked via pidfiles under ``~/.pablo/agents/``.
Verified 2026-07-26: an unregistered repo's worktree yields
``selector_not_found``, hence the per-call fallback rather than a startup
probe.
"""

from __future__ import annotations

import json
import os
import shlex
import subprocess
import sys
import time
from dataclasses import dataclass
from pathlib import Path

from pablo.model import utcnow
from pablo.providers import run_cli

ORCA_WAIT_TIMEOUT_MS = 3_600_000
RUNNING_STATES = {"working", "running"}


@dataclass
class SessionInfo:
    handle: str
    status: str  # "running" | "waiting"


def agents_dir() -> Path:
    override = os.environ.get("PABLO_AGENTS_DIR")
    if override:
        return Path(override)
    return Path("~/.pablo/agents").expanduser()


ORCA_CALL_TIMEOUT_S = 60


def _orca(argv: list[str]) -> dict | None:
    """Run an orca command; None when orca is unusable for this call.

    Timeout matters: orca has been observed to hang when invoked outside an
    interactive session (e.g. under the systemd timer) — a hung call must
    degrade to the headless fallback, not wedge the poller.
    """
    try:
        out = run_cli(["orca", *argv, "--json"], check=False, timeout=ORCA_CALL_TIMEOUT_S)
        data = json.loads(out)
    except Exception:
        return None
    if not isinstance(data, dict) or not data.get("ok"):
        return None
    return data.get("result") or {}


def launch(worktree: Path, agent: str, prompt: str) -> str:
    """Start an opencode agent in the worktree; returns a handle.

    Orca path opens the interactive TUI (``opencode <wt> --agent ...
    --prompt ...``) in an Orca terminal pane so the user can approve
    permission prompts inline. Headless fallback (no TTY) uses
    ``opencode run``. Handle is ``term_...`` for Orca-managed runs,
    ``pid:<n>`` for headless.
    """
    command = (
        f"opencode {shlex.quote(str(worktree))} "
        f"--agent {agent} --prompt {shlex.quote(prompt)}"
    )
    result = _orca(
        ["terminal", "create",
         "--worktree", f"path:{worktree}",
         "--title", f"pablo:{agent}",
         "--command", command]
    )
    if result:
        handle = (
            result.get("handle")
            or result.get("agentTerminalHandle")
            or (result.get("startupTerminal") or {}).get("handle")
            or (result.get("terminal") or {}).get("handle")
        )
        if handle:
            return str(handle)
    return _launch_headless(worktree, agent, prompt)


def _launch_headless(worktree: Path, agent: str, prompt: str) -> str:
    # No TTY here, so the interactive TUI is not an option — fall back to
    # `opencode run`. NOTE: without --auto, any permission resolving to
    # "ask" auto-rejects (the original bug). The Orca/TUI path is the
    # preferred launch; this fallback only fires when Orca refuses (e.g.
    # repo not registered in Orca). Add --auto here if you want the
    # fallback to run autonomously.
    logs = agents_dir() / "logs"
    logs.mkdir(parents=True, exist_ok=True)
    log = open(logs / f"{int(time.time())}-{agent}.log", "ab")
    proc = subprocess.Popen(
        ["opencode", "run", "--agent", agent, "--dir", str(worktree), prompt],
        stdout=log,
        stderr=subprocess.STDOUT,
        stdin=subprocess.DEVNULL,
        start_new_session=True,
    )
    record = {
        "pid": proc.pid,
        "worktree": str(worktree),
        "agent": agent,
        "started_at": utcnow(),
    }
    (agents_dir() / f"{proc.pid}.json").write_text(json.dumps(record))
    return f"pid:{proc.pid}"


def active_sessions(worktree: Path) -> list[SessionInfo]:
    """Agent sessions currently attached to ``worktree`` (Orca + headless)."""
    sessions: list[SessionInfo] = []
    result = _orca(["worktree", "ps", "--limit", "200"])
    if result:
        for wt in result.get("worktrees", []):
            if Path(wt.get("path", "")) != Path(worktree):
                continue
            for agent in wt.get("agents") or []:
                status = (
                    "running"
                    if agent.get("state") in RUNNING_STATES
                    else "waiting"
                )
                sessions.append(SessionInfo(handle=str(agent.get("paneKey")), status=status))
    sessions.extend(_headless_sessions(worktree))
    return sessions


def _headless_sessions(worktree: Path) -> list[SessionInfo]:
    directory = agents_dir()
    if not directory.is_dir():
        return []
    sessions = []
    for pidfile in sorted(directory.glob("*.json")):
        try:
            record = json.loads(pidfile.read_text())
        except json.JSONDecodeError:
            pidfile.unlink(missing_ok=True)
            continue
        pid = record.get("pid")
        if not pid or not _pid_alive(pid):
            pidfile.unlink(missing_ok=True)
            continue
        if Path(record.get("worktree", "")) == Path(worktree):
            # A headless run has no idle signal; it counts as running.
            sessions.append(SessionInfo(handle=f"pid:{pid}", status="running"))
    return sessions


def _pid_alive(pid: int) -> bool:
    try:
        os.kill(pid, 0)
    except OSError:
        return False
    return True


def wait_for_handle(handle: str, timeout_s: int = ORCA_WAIT_TIMEOUT_MS // 1000) -> None:
    """Block until the agent run behind ``handle`` finishes (or timeout)."""
    if handle.startswith("pid:"):
        pid = int(handle.removeprefix("pid:"))
        deadline = time.monotonic() + timeout_s
        while _pid_alive(pid) and time.monotonic() < deadline:
            time.sleep(5)
        return
    try:
        run_cli(
            ["orca", "terminal", "wait", "--terminal", handle,
             "--for", "exit", "--timeout-ms", str(timeout_s * 1000), "--json"],
            check=False,
            timeout=timeout_s + 120,
        )
    except Exception:
        return  # orca gone/hung: treat the run as finished rather than wedging


def spawn_watcher(
    project: str, branch: str, handle: str, then: str, expect_state: str | None = None
) -> None:
    """Detached ``pablo watch-agent`` process: waits for the agent run to
    finish, then applies the follow-up (e.g. switching the PR to draft).
    ``expect_state`` guards the follow-up: it is skipped if the task has
    moved to another state by the time the agent finishes."""
    argv = [sys.executable, "-m", "pablo.cli", "watch-agent",
            "--project", project, "--branch", branch,
            "--handle", handle, "--then", then]
    if expect_state:
        argv += ["--expect-state", expect_state]
    subprocess.Popen(
        argv,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
