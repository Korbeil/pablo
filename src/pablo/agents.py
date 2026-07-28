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

A brand-new worktree (``launch()``/``run_startup_script()`` called right
after ``git worktree add``) can hit the same ``selector_not_found`` before
Orca has indexed it — observed 2026-07-28 on OMS-6393, where the repo was
registered weeks earlier yet the very first ``terminal create`` still
failed. ``_orca_with_retry`` retries several times to ride out that race,
and every exhausted fallback is logged to
``<agents_dir>/logs/orca-fallback.log`` so a genuinely-unregistered repo
and this race are distinguishable after the fact.

``launch()``/``run_startup_script()`` are thin: they each ``Popen`` a
detached ``pablo internal-launch-agent`` / ``internal-run-startup-script``
subprocess (mirroring ``spawn_watcher`` below) and return immediately. The
actual Orca-retry/headless-fallback work happens in ``_do_launch_agent`` /
``_do_run_startup_script``, which only ever run inside that detached
subprocess. This two-hop indirection exists because ``orca`` has been
observed to hang when invoked outside an interactive session (e.g. under
the systemd timer or a non-interactive ``pablo state`` call) — running the
retry loop inline in the CLI process made ``pablo state ...`` appear to
hang for minutes. Backgrounding it also means the retry loop no longer has
to stay cheap to keep the CLI responsive, so it can retry patiently enough
for the indexing race above to actually resolve instead of quietly
degrading to a headless (Orca-invisible) run.
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
# The retry loop below only ever runs inside the detached launcher
# subprocess (see launch()/run_startup_script()), never inline in the
# interactive CLI, so it can afford to be patient about the
# just-created-worktree indexing race instead of racing a tight budget.
ORCA_LAUNCH_RETRIES = 10
ORCA_LAUNCH_RETRY_DELAY_S = 3.0


def _orca(argv: list[str]) -> tuple[dict | None, str | None]:
    """Run an orca command.

    Returns ``(result, None)`` on success or ``(None, reason)`` describing
    why it failed (exception message, or the raw ``ok:false`` payload) so
    callers that fall back to headless can log *why*.

    Timeout matters: orca has been observed to hang when invoked outside an
    interactive session (e.g. under the systemd timer) — a hung call must
    degrade to the headless fallback, not wedge the poller.
    """
    try:
        out = run_cli(["orca", *argv, "--json"], check=False, timeout=ORCA_CALL_TIMEOUT_S)
        data = json.loads(out)
    except Exception as exc:
        return None, f"{type(exc).__name__}: {exc}"
    if not isinstance(data, dict) or not data.get("ok"):
        return None, f"ok:false response: {data!r}"
    return data.get("result") or {}, None


def _log_orca_fallback(worktree: Path, label: str, reason: str | None) -> None:
    logs = agents_dir() / "logs"
    logs.mkdir(parents=True, exist_ok=True)
    with open(logs / "orca-fallback.log", "a") as f:
        f.write(f"{utcnow()} worktree={worktree} label={label} reason={reason}\n")


def _orca_with_retry(argv: list[str], *, worktree: Path, label: str) -> dict | None:
    """``_orca`` with a few retries, to ride out the just-created-worktree
    indexing race (Orca hasn't yet resolved a brand-new ``path:`` selector).
    Logs the last failure reason once retries are exhausted."""
    reason = None
    for attempt in range(ORCA_LAUNCH_RETRIES):
        result, reason = _orca(argv)
        if result is not None:
            return result
        if attempt < ORCA_LAUNCH_RETRIES - 1:
            time.sleep(ORCA_LAUNCH_RETRY_DELAY_S)
    _log_orca_fallback(worktree, label, reason)
    return None


def _do_launch_agent(worktree: Path, agent: str, prompt: str) -> str:
    """Start an opencode agent in the worktree; returns a handle.

    Orca path opens the interactive TUI (``opencode <wt> --agent ...
    --prompt ...``) in an Orca terminal pane so the user can approve
    permission prompts inline. Headless fallback (no TTY) uses
    ``opencode run``. Handle is ``term_...`` for Orca-managed runs,
    ``pid:<n>`` for headless.

    Blocking: retries against Orca for up to
    ``ORCA_LAUNCH_RETRIES * ORCA_LAUNCH_RETRY_DELAY_S`` seconds before
    falling back. Only call this from the detached launcher subprocess
    (see ``launch()``), never inline from the interactive CLI.
    """
    command = (
        f"opencode {shlex.quote(str(worktree))} "
        f"--agent {agent} --prompt {shlex.quote(prompt)}"
    )
    result = _orca_with_retry(
        ["terminal", "create",
         "--worktree", f"path:{worktree}",
         "--title", f"pablo:{agent}",
         "--command", command],
        worktree=worktree,
        label=agent,
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


def _do_run_startup_script(worktree: Path, script: Path) -> str:
    """Run a per-project startup script in a new Orca terminal.

    Mirrors ``_do_launch_agent()``'s Orca-first/headless-fallback shape,
    but runs an arbitrary bash script instead of an opencode agent.
    Fire-and-forget: the caller does not wait for it to finish.

    Blocking, same budget as ``_do_launch_agent()`` — only call from the
    detached launcher subprocess (see ``run_startup_script()``).
    """
    command = f"bash {shlex.quote(str(script))}"
    result = _orca_with_retry(
        ["terminal", "create",
         "--worktree", f"path:{worktree}",
         "--title", "pablo:startup-script",
         "--command", command],
        worktree=worktree,
        label="startup-script",
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


def launch(worktree: Path, agent: str, prompt: str) -> str:
    """Fire-and-forget: launch ``agent`` on ``worktree`` in a detached
    subprocess and return immediately. The returned ``pid:<n>`` handle
    identifies the detached launcher subprocess itself (for logs only) —
    it is not a handle to the eventual Orca/headless agent run and cannot
    be waited on. See ``_do_launch_agent`` for the actual launch logic."""
    pid = _spawn_detached_cli(
        ["internal-launch-agent",
         "--worktree", str(worktree),
         "--agent", agent,
         "--prompt", prompt]
    )
    return f"pid:{pid}"


def run_startup_script(worktree: Path, script: Path) -> str:
    """Fire-and-forget: run the project's startup ``script`` on
    ``worktree`` in a detached subprocess and return immediately. See
    ``launch()`` for the handle semantics and ``_do_run_startup_script``
    for the actual execution logic."""
    pid = _spawn_detached_cli(
        ["internal-run-startup-script",
         "--worktree", str(worktree),
         "--script", str(script)]
    )
    return f"pid:{pid}"


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
