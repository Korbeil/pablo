"""Launching OpenCode agents on worktrees and querying their activity.

Primary path is the Orca CLI (spec, "Implementation architecture"):

    orca terminal create --worktree path:<wt> --title pablo:<agent> \
        --command "opencode <wt> --agent <agent> --prompt <prompt>" --json

Activity is read from ``orca worktree ps --json`` (per-worktree ``agents[]``
with a ``state`` field). Orca only resolves ``path:`` selectors for
worktrees of repos registered in Orca (``orca repo add``); when it refuses
(e.g. ``selector_not_found``) or is unreachable, PABLO falls back to a
headless ``opencode run`` tracked via pidfiles under ``~/.pablo/agents/``.
Verified 2026-07-26: an unregistered repo's worktree yields
``selector_not_found``, hence the per-call fallback rather than a startup
probe.

``launch()``/``run_startup_script()`` are thin: they each ``Popen`` a
detached ``pablo internal-launch-agent`` / ``internal-run-startup-script``
subprocess (mirroring ``spawn_watcher`` below) and return immediately. The
actual Orca-call/headless-fallback work happens in ``_do_launch_agent`` /
``_do_run_startup_script``, which only ever run inside that detached
subprocess. This indirection exists because ``orca`` has been observed to
hang when invoked outside an interactive session (e.g. under the systemd
timer or a non-interactive ``pablo state`` call) — running the call inline
in the CLI process made ``pablo state ...`` appear to hang for minutes.

``_enter_in_progress`` fires ``launch()`` and ``run_startup_script()``
back-to-back; a per-worktree ``flock`` (``_acquire_launch_lock``)
serialises their ``terminal create`` spans so they don't race on the same
worktree.
"""

from __future__ import annotations

import contextlib
import fcntl
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


def _orca(
    argv: list[str], *, per_call_timeout: float = ORCA_CALL_TIMEOUT_S
) -> tuple[dict | None, str | None]:
    """Run an orca command.

    Returns ``(result, None)`` on success or ``(None, reason)`` describing
    why it failed. The reason embeds the raw ``stdout`` that orca returned
    (Phase 1 diagnostics): the previously-opaque empty-stdout failure mode
    surfaced only as ``JSONDecodeError: Expecting value: line 1 column 1``
    with no record of what orca actually emitted, hiding the real cause
    (observed 2026-07-29 on ``oms-6421`` / ``acme-8266``).

    Timeout matters: orca has been observed to hang when invoked outside an
    interactive session (e.g. under the systemd timer) — a hung call must
    degrade to the headless fallback, not wedge the poller.
    """
    out = ""
    try:
        out = run_cli(["orca", *argv, "--json"], check=False, timeout=per_call_timeout)
        data = json.loads(out)
    except Exception as exc:
        return None, f"{type(exc).__name__}: {exc}; raw_stdout={out!r}"
    if not isinstance(data, dict) or not data.get("ok"):
        return None, f"ok:false response: {data!r}"
    return data.get("result") or {}, None


def _log_orca_fallback(worktree: Path, label: str, reason: str | None) -> None:
    logs = agents_dir() / "logs"
    logs.mkdir(parents=True, exist_ok=True)
    with open(logs / "orca-fallback.log", "a") as f:
        f.write(f"{utcnow()} worktree={worktree} label={label} reason={reason}\n")


@contextlib.contextmanager
def _acquire_launch_lock(worktree: Path):
    """Per-worktree ``flock`` serialising ``terminal create`` spans.

    ``_enter_in_progress`` fires ``launch()`` and ``run_startup_script()``
    back-to-back; both spawn detached launchers that would otherwise race
    on the same cold ``orca terminal create --worktree path:<NEW>`` call.
    Holding this lock across the warm-up + create span in each launcher
    makes them sequential for the same worktree only — different worktrees
    get independent lockfiles and run in parallel as before.
    """
    locks = agents_dir() / "locks"
    locks.mkdir(parents=True, exist_ok=True)
    stem = "".join(c if c.isalnum() else "_" for c in str(worktree)) or "root"
    lockfile = open(locks / f"{stem}.launch.lock", "w")
    try:
        fcntl.flock(lockfile.fileno(), fcntl.LOCK_EX)
        yield
    finally:
        fcntl.flock(lockfile.fileno(), fcntl.LOCK_UN)
        lockfile.close()


def _do_launch_agent(worktree: Path, agent: str, prompt: str) -> str:
    """Start an opencode agent in the worktree; returns a handle.

    Orca path opens the interactive TUI (``opencode <wt> --agent ...
    --prompt ...``) in an Orca terminal pane so the user can approve
    permission prompts inline. Headless fallback (no TTY) uses
    ``opencode run``. Handle is ``term_...`` for Orca-managed runs,
    ``pid:<n>`` for headless.

    Blocking: a single ``orca terminal create`` call (60s timeout) before
    falling back. Only call this from the detached launcher subprocess
    (see ``launch()``), never inline from the interactive CLI. The
    per-worktree ``_acquire_launch_lock`` serialises this against a
    concurrent ``run_startup_script`` on the same worktree.
    """
    command = (
        f"opencode {shlex.quote(str(worktree))} "
        f"--agent {agent} --prompt {shlex.quote(prompt)}"
    )
    with _acquire_launch_lock(worktree):
        result, reason = _orca([
            "terminal", "create",
            "--worktree", f"path:{worktree}",
            "--title", f"pablo:{agent}",
            "--command", command,
        ])
    if result:
        handle = (
            result.get("handle")
            or result.get("agentTerminalHandle")
            or (result.get("startupTerminal") or {}).get("handle")
            or (result.get("terminal") or {}).get("handle")
        )
        if handle:
            return str(handle)
    _log_orca_fallback(worktree, agent, reason)
    return _launch_headless(worktree, agent, prompt)


def _do_run_startup_script(worktree: Path, script: Path) -> str:
    """Run a per-project startup script in a new Orca terminal.

    Mirrors ``_do_launch_agent()``'s Orca-first/headless-fallback shape,
    but runs an arbitrary bash script instead of an opencode agent.
    Fire-and-forget: the caller does not wait for it to finish.

    Blocking, single ``orca terminal create`` call — only call from the
    detached launcher subprocess (see ``run_startup_script()``). Shares
    ``_do_launch_agent``'s per-worktree lock so the two don't race on the
    same ``terminal create`` call.

    The command is ``bash <script>; exec bash`` so when the script
    finishes (success or failure — ``;`` not ``&&``) the tab drops into
    an interactive shell at the worktree root instead of auto-closing
    on PTY EOF. ``opencode``'s TUI is itself long-lived and needs no
    such wrapping, so only this path does it.
    """
    command = f"bash {shlex.quote(str(script))}; exec bash"
    with _acquire_launch_lock(worktree):
        result, reason = _orca([
            "terminal", "create",
            "--worktree", f"path:{worktree}",
            "--title", "pablo:startup-script",
            "--command", command,
        ])
    if result:
        handle = (
            result.get("handle")
            or result.get("agentTerminalHandle")
            or (result.get("startupTerminal") or {}).get("handle")
            or (result.get("terminal") or {}).get("handle")
        )
        if handle:
            return str(handle)
    _log_orca_fallback(worktree, "startup-script", reason)
    return _launch_headless_command(worktree, "startup-script", command)


def _spawn_detached_cli(argv: list[str]) -> int:
    proc = subprocess.Popen(
        [sys.executable, "-m", "pablo.cli", *argv],
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    return proc.pid


def launch(
    worktree: Path, agent: str, prompt: str,
    project: str, branch: str,
) -> str:
    """Fire-and-forget: launch ``agent`` on ``worktree`` in a detached
    subprocess and return immediately. The returned ``pid:<n>`` handle
    identifies the detached launcher subprocess itself (for logs only) —
    it is not a handle to the eventual Orca/headless agent run and cannot
    be waited on. See ``_do_launch_agent`` for the actual launch logic."""
    pid = _spawn_detached_cli(
        ["internal-launch-agent",
         "--worktree", str(worktree),
         "--agent", agent,
         "--prompt", prompt,
         "--project", project,
         "--branch", branch]
    )
    return f"pid:{pid}"


def run_startup_script(
    worktree: Path, script: Path,
    project: str, branch: str,
) -> str:
    """Fire-and-forget: run the project's startup ``script`` on
    ``worktree`` in a detached subprocess and return immediately. See
    ``launch()`` for the handle semantics and ``_do_run_startup_script``
    for the actual execution logic."""
    pid = _spawn_detached_cli(
        ["internal-run-startup-script",
         "--worktree", str(worktree),
         "--script", str(script),
         "--project", project,
         "--branch", branch]
    )
    return f"pid:{pid}"


def set_worktree_display_name(
    worktree: Path, name: str, issue_number: str | None = None
) -> None:
    """Override Orca's worktree ``displayName`` (e.g. ``OMS-6407`` instead
    of the lowercase branch auto-derived from the path) and clear/set the
    linked GitHub issue.

    ``issue_number=None`` passes ``--issue null``, explicitly telling Orca
    there is no linked PR/issue — preventing Orca from auto-detecting a
    wrong PR from the branch name's trailing digits. Pass the GitHub issue
    number string (e.g. ``"273"``) to link the correct issue instead.

    Best-effort: silently ignores failures (no Orca, worktree not yet
    indexed, repo not registered). Orca's own post-first-message
    self-correction remains the fallback when this call loses the
    indexing race, so callers needn't retry."""
    _orca(
        ["worktree", "set",
         "--worktree", f"path:{worktree}",
         "--display-name", name,
         "--issue", issue_number if issue_number is not None else "null"],
        per_call_timeout=ORCA_CALL_TIMEOUT_S,
    )


def _refresh_agent_display_cache(
    project: str, branch: str, worktree: Path
) -> None:
    """Immediately refresh ``cached_agent_*`` so ``pablo list`` shows
    agent activity without waiting for the poller cycle.

    Called from the detached launcher subprocess right after the Orca
    terminal / headless process exists, and then again when the agent
    finishes. Non-blocking: uses a 2 s lock timeout so a concurrent
    poller run doesn't stall the launcher; the poller recovers on its
    next cycle anyway."""
    try:
        sessions = active_sessions(worktree)
        parts: list[str] = []
        running = sum(1 for s in sessions if s.status == "running")
        waiting = sum(1 for s in sessions if s.status == "waiting")
        if running:
            parts.append(f"🏃 {running}")
        if waiting:
            parts.append(f"💭 {waiting}")
        activity = " · ".join(parts) if parts else "-"
        from pablo.store import Store, task_lock

        store = Store()
        with task_lock(store, project, branch, timeout_s=2):
            task = store.get(project, branch)
            if task is None:
                return
            task.cached_agent_count = len(sessions)
            task.cached_agent_activity = activity
            task.cached_at = utcnow()
            store.save(task)
    except Exception:
        pass


def _launch_headless_command(worktree: Path, label: str, command: str) -> str:
    logs = agents_dir() / "logs"
    logs.mkdir(parents=True, exist_ok=True)
    log = open(logs / f"{int(time.time())}-{label}.log", "ab")
    proc = subprocess.Popen(
        ["bash", "-c", command],
        cwd=worktree,
        stdout=log,
        stderr=subprocess.STDOUT,
        stdin=subprocess.DEVNULL,
        start_new_session=True,
    )
    record = {
        "pid": proc.pid,
        "worktree": str(worktree),
        "agent": label,
        "started_at": utcnow(),
    }
    (agents_dir() / f"{proc.pid}.json").write_text(json.dumps(record))
    return f"pid:{proc.pid}"


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


def _sessions_by_worktree_from_orca(orca_worktrees: list[dict]) -> dict[Path, list[SessionInfo]]:
    grouped: dict[Path, list[SessionInfo]] = {}
    for wt in orca_worktrees:
        path = Path(wt.get("path", ""))
        for agent in wt.get("agents") or []:
            status = "running" if agent.get("state") in RUNNING_STATES else "waiting"
            grouped.setdefault(path, []).append(
                SessionInfo(handle=str(agent.get("paneKey")), status=status)
            )
    return grouped


def _headless_sessions_by_worktree() -> dict[Path, list[SessionInfo]]:
    directory = agents_dir()
    grouped: dict[Path, list[SessionInfo]] = {}
    if not directory.is_dir():
        return grouped
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
        # A headless run has no idle signal; it counts as running.
        grouped.setdefault(Path(record.get("worktree", "")), []).append(
            SessionInfo(handle=f"pid:{pid}", status="running")
        )
    return grouped


def active_sessions(worktree: Path) -> list[SessionInfo]:
    """Agent sessions currently attached to ``worktree`` (Orca + headless)."""
    result, _ = _orca(["worktree", "ps", "--limit", "200"])
    orca_grouped = _sessions_by_worktree_from_orca(result.get("worktrees", [])) if result else {}
    sessions = list(orca_grouped.get(Path(worktree), []))
    sessions.extend(_headless_sessions_by_worktree().get(Path(worktree), []))
    return sessions


def bulk_active_sessions(worktrees: list[Path]) -> dict[Path, list[SessionInfo]]:
    """Same data as ``active_sessions()`` for each of ``worktrees``, but one
    ``orca worktree ps`` call and one pidfile-directory scan total instead of
    one of each per worktree."""
    wanted = {Path(w) for w in worktrees}
    result, _ = _orca(["worktree", "ps", "--limit", "200"])
    orca_grouped = _sessions_by_worktree_from_orca(result.get("worktrees", [])) if result else {}
    headless_grouped = _headless_sessions_by_worktree()
    return {
        wt: list(orca_grouped.get(wt, [])) + headless_grouped.get(wt, [])
        for wt in wanted
    }


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
