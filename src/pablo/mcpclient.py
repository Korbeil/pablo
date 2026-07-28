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

# The bridge and its Node/nvm helper processes don't care what directory
# they run in, so they must never inherit the caller's cwd — a shell left
# sitting in a task worktree the background poller has since deleted would
# otherwise crash npx with a uv_cwd ENOENT.
_SAFE_CWD = str(Path.home())


def _node_major(node: Path) -> int:
    try:
        out = subprocess.run(
            [str(node), "--version"], capture_output=True, text=True, timeout=10,
            cwd=_SAFE_CWD,
        ).stdout.strip()
        match = re.match(r"v(\d+)", out)
        return int(match.group(1)) if match else -1
    except Exception:
        return -1


NVM_RESOLVE_TIMEOUT_S = 15


def nvmrc_spec() -> str:
    """The Node version spec pinned by the repo's .nvmrc (fallback: default).

    The user's nvm `default` alias is deliberately old (legacy projects),
    so PABLO pins its own requirement in-repo, the nvm-idiomatic way.
    """
    nvmrc = Path(__file__).resolve().parents[2] / ".nvmrc"
    if nvmrc.is_file():
        spec = nvmrc.read_text().strip()
        if spec:
            return spec
    return "default"


def _nvm_dir() -> Path | None:
    for candidate in (os.environ.get("NVM_DIR"), Path.home() / ".nvm"):
        if candidate and (Path(candidate) / "nvm.sh").is_file():
            return Path(candidate)
    return None


def _nvm_which(spec: str) -> str | None:
    """Resolve a Node binary through nvm itself (nvm is a shell function,
    so it has to be sourced). None when nvm can't resolve the spec."""
    nvm_dir = _nvm_dir()
    if nvm_dir is None:
        return None
    env = os.environ.copy()
    env["NVM_DIR"] = str(nvm_dir)
    try:
        proc = subprocess.run(
            ["bash", "-c", '. "$NVM_DIR/nvm.sh" >/dev/null 2>&1; nvm which "$1"',
             "bash", spec],
            capture_output=True,
            text=True,
            timeout=NVM_RESOLVE_TIMEOUT_S,
            env=env,
            cwd=_SAFE_CWD,
        )
    except Exception:
        return None
    if proc.returncode != 0:
        return None
    node = proc.stdout.strip().splitlines()[-1] if proc.stdout.strip() else ""
    return node if node.endswith("/node") or Path(node).name == "node" else None


def npx_path() -> str:
    """The npx to run the mcp-remote bridge with, resolved through nvm.

    The systemd user manager's PATH carries nvm's old default Node
    (observed: v16 under the timer vs v24 in shells) and mcp-remote needs
    Node >= MIN_NODE_MAJOR — so ask nvm for the repo's pinned version
    (.nvmrc) instead of trusting PATH. PATH is only the fallback when nvm
    isn't installed at all.
    """
    if _nvm_dir() is not None:
        spec = nvmrc_spec()
        node = _nvm_which(spec)
        if node is None:
            raise PabloError(
                f"nvm cannot resolve Node {spec!r} — run: nvm install {spec}"
            )
        major = _node_major(Path(node))
        if major < MIN_NODE_MAJOR:
            raise PabloError(
                f"Node {spec!r} resolves to v{major}, but mcp-remote needs "
                f">= {MIN_NODE_MAJOR} — bump .nvmrc or run: nvm install {MIN_NODE_MAJOR}"
            )
        npx = Path(node).parent / "npx"
        if not npx.exists():
            raise PabloError(f"npx not found next to {node}")
        return str(npx)

    which = shutil.which("npx")
    if which is None:
        raise PabloError("npx not found — install Node.js (it runs the mcp-remote bridge)")
    major = _node_major(Path(which).parent / "node")
    if major < MIN_NODE_MAJOR:
        raise PabloError(
            f"PATH Node is v{major}, but mcp-remote needs >= {MIN_NODE_MAJOR}"
        )
    return which


def default_argv() -> list[str]:
    return [npx_path(), "-y", "mcp-remote", ATLASSIAN_MCP_URL]


RETRY_ATTEMPTS = 3        # 1 initial try + 2 retries
RETRY_BACKOFF_S = (1, 2)  # sleep before attempt 2, before attempt 3

# Substrings of PabloError messages that indicate a transient, connection-
# level failure (the bridge never got to answer) rather than a real auth
# or tool problem — e.g. the ConnectTimeoutError observed reaching
# mcp.atlassian.com from a flaky network. Only these are retried.
TRANSIENT_MARKERS = (
    "ConnectTimeoutError",
    "ECONNREFUSED",
    "ETIMEDOUT",
    "ENOTFOUND",
    "EAI_AGAIN",
    "bridge exited unexpectedly",
)


def is_transient_error(message: str) -> bool:
    return any(marker in message for marker in TRANSIENT_MARKERS)


def call_tool_with_retry(
    tool: str, arguments: dict, *, argv: list[str] | None = None
) -> Any:
    """Call an MCP tool, retrying a fresh bridge session on transient
    connection failures (short backoff, never on auth/tool errors)."""
    last_exc: PabloError | None = None
    for attempt in range(RETRY_ATTEMPTS):
        try:
            with McpClient(argv=argv) as client:
                return client.call_tool(tool, arguments)
        except PabloError as exc:
            last_exc = exc
            if not is_transient_error(str(exc)) or attempt == RETRY_ATTEMPTS - 1:
                raise
            time.sleep(RETRY_BACKOFF_S[attempt])
    raise last_exc  # pragma: no cover — loop always returns or raises above


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
                cwd=_SAFE_CWD,
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
