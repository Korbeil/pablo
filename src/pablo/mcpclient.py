"""Minimal synchronous MCP client over a stdio child process.

PABLO's Jira access goes through the Atlassian MCP server
(https://mcp.atlassian.com/v1/mcp) via the ``mcp-remote`` bridge, which
owns the OAuth flow and caches tokens under ``~/.mcp-auth/`` — PABLO
itself stores no tokens (spec amendment 2026-07-26; the no-tokens rule is
preserved, the bridge's cache plays the role of a CLI's own login).

The protocol here is JSON-RPC 2.0, newline-delimited over the child's
stdin/stdout: ``initialize`` → ``notifications/initialized`` →
``tools/call``. Every read has a hard deadline — a hung bridge must never
wedge the poller or the doctor (see the orca lesson in README).
"""

from __future__ import annotations

import json
import os
import re
import select
import shutil
import subprocess
import time
from pathlib import Path
from typing import Any

from pablo import PabloError

ATLASSIAN_MCP_URL = "https://mcp.atlassian.com/v1/mcp"
MCP_CALL_TIMEOUT_S = 60
PROTOCOL_VERSION = "2024-11-05"
MIN_NODE_MAJOR = 18  # mcp-remote requirement


def _node_major(node: Path) -> int:
    try:
        out = subprocess.run(
            [str(node), "--version"], capture_output=True, text=True, timeout=10
        ).stdout.strip()
        match = re.match(r"v(\d+)", out)
        return int(match.group(1)) if match else -1
    except Exception:
        return -1


def npx_path() -> str:
    """The npx of the newest available Node.

    PATH alone is not enough: the systemd user manager's PATH can point at
    an older nvm Node (observed: v16 under the timer vs v24 in shells),
    and mcp-remote needs Node >= 18 — so scan nvm installs too and pick
    the newest.
    """
    candidates: list[Path] = []
    which = shutil.which("npx")
    if which:
        candidates.append(Path(which))
    candidates.extend(sorted(Path.home().glob(".nvm/versions/node/*/bin/npx")))
    best: Path | None = None
    best_major = -1
    for npx in candidates:
        major = _node_major(npx.parent / "node")
        if major > best_major:
            best, best_major = npx, major
    if best is None:
        raise PabloError("npx not found — install Node.js (it runs the mcp-remote bridge)")
    if best_major < MIN_NODE_MAJOR:
        raise PabloError(
            f"newest Node found is v{best_major}, but mcp-remote needs >= {MIN_NODE_MAJOR}"
        )
    return str(best)


def default_argv() -> list[str]:
    return [npx_path(), "-y", "mcp-remote", ATLASSIAN_MCP_URL]


def auth_cache_present() -> bool:
    """True iff mcp-remote has cached OAuth tokens. Never spawns anything —
    callers use this to fail fast instead of triggering a browser flow."""
    root = Path.home() / ".mcp-auth"
    if not root.is_dir():
        return False
    return any(root.glob("**/*tokens.json"))


class McpClient:
    def __init__(self, argv: list[str] | None = None, timeout_s: float = MCP_CALL_TIMEOUT_S):
        self.argv = argv if argv is not None else default_argv()
        self.timeout_s = timeout_s
        self._proc: subprocess.Popen | None = None
        self._next_id = 0

    # ------------------------------------------------------------- pipes --

    def _send(self, payload: dict) -> None:
        assert self._proc is not None and self._proc.stdin is not None
        self._proc.stdin.write(json.dumps(payload) + "\n")
        self._proc.stdin.flush()

    def _read_response(self, expect_id: int) -> dict:
        assert self._proc is not None and self._proc.stdout is not None
        deadline = time.monotonic() + self.timeout_s
        fd = self._proc.stdout
        while True:
            remaining = deadline - time.monotonic()
            if remaining <= 0:
                self._kill()
                raise PabloError(
                    f"jira mcp timed out after {self.timeout_s}s "
                    f"({' '.join(self.argv)}){self._stderr_tail()}"
                )
            ready, _, _ = select.select([fd], [], [], min(remaining, 1.0))
            if not ready:
                continue
            line = fd.readline()
            if not line:
                try:
                    self._proc.wait(timeout=5)
                except subprocess.TimeoutExpired:
                    self._kill()
                raise PabloError(
                    f"jira mcp bridge exited unexpectedly{self._stderr_tail()}"
                )
            line = line.strip()
            if not line:
                continue
            try:
                msg = json.loads(line)
            except json.JSONDecodeError:
                continue  # bridges may log non-JSON lines on stdout
            if msg.get("id") == expect_id:
                return msg

    def _stderr_tail(self) -> str:
        if self._proc is None or self._proc.stderr is None:
            return ""
        try:
            if self._proc.poll() is None:
                os.set_blocking(self._proc.stderr.fileno(), False)
            tail = self._proc.stderr.read() or ""
        except Exception:
            return ""
        tail = tail.strip()
        return f"\nbridge stderr: {tail[-500:]}" if tail else ""

    def _kill(self) -> None:
        if self._proc is not None and self._proc.poll() is None:
            self._proc.kill()
            self._proc.wait()

    # ----------------------------------------------------------- session --

    def __enter__(self) -> "McpClient":
        env = os.environ.copy()
        # npx's shebang is `#!/usr/bin/env node`: the chosen Node must be
        # first in the child's PATH or an older PATH node would run it.
        bin_dir = str(Path(self.argv[0]).parent)
        if bin_dir not in ("", "."):
            env["PATH"] = f"{bin_dir}{os.pathsep}{env.get('PATH', '')}"
        try:
            self._proc = subprocess.Popen(
                self.argv,
                stdin=subprocess.PIPE,
                stdout=subprocess.PIPE,
                stderr=subprocess.PIPE,
                text=True,
                env=env,
            )
        except FileNotFoundError:
            raise PabloError(
                f"{self.argv[0]!r} is not installed (needed to reach the Jira MCP)"
            )
        self._next_id += 1
        self._send(
            {
                "jsonrpc": "2.0",
                "id": self._next_id,
                "method": "initialize",
                "params": {
                    "protocolVersion": PROTOCOL_VERSION,
                    "capabilities": {},
                    "clientInfo": {"name": "pablo", "version": "0.1.0"},
                },
            }
        )
        response = self._read_response(self._next_id)
        if "error" in response:
            self._kill()
            raise PabloError(f"jira mcp initialize failed: {response['error']}")
        self._send({"jsonrpc": "2.0", "method": "notifications/initialized"})
        return self

    def __exit__(self, *exc) -> None:
        self._kill()

    # -------------------------------------------------------------- call --

    def call_tool(self, name: str, arguments: dict) -> Any:
        self._next_id += 1
        self._send(
            {
                "jsonrpc": "2.0",
                "id": self._next_id,
                "method": "tools/call",
                "params": {"name": name, "arguments": arguments},
            }
        )
        response = self._read_response(self._next_id)
        if "error" in response:
            raise PabloError(f"jira mcp call {name} failed: {response['error']}")
        result = response.get("result") or {}
        content = result.get("content") or []
        text = "\n".join(
            part.get("text", "") for part in content if part.get("type") == "text"
        )
        if result.get("isError"):
            raise PabloError(f"jira mcp tool {name} errored: {text or result}")
        if not text:
            return result
        try:
            return json.loads(text)
        except json.JSONDecodeError:
            return text
