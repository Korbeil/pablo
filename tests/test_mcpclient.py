import json
import sys
from pathlib import Path

import pytest

from pablo import PabloError, mcpclient
from pablo.mcpclient import McpClient, auth_cache_present

FAKE_SERVER = str(Path(__file__).parent / "fake_mcp_server.py")


def fake_client(monkeypatch, responses: dict, timeout: float | None = None) -> McpClient:
    monkeypatch.setenv("FAKE_MCP_RESPONSES", json.dumps(responses))
    kwargs = {"argv": [sys.executable, FAKE_SERVER]}
    if timeout is not None:
        kwargs["timeout_s"] = timeout
    return McpClient(**kwargs)


def test_initialize_handshake_and_call(monkeypatch):
    with fake_client(
        monkeypatch, {"atlassianUserInfo": {"email": "baptiste@example.com"}}
    ) as client:
        result = client.call_tool("atlassianUserInfo", {})
        assert result == {"email": "baptiste@example.com"}
        # a second call on the same session works too
        assert client.call_tool("other", {}) == {"echo": "other"}


def test_tool_error_raises_pabloerror(monkeypatch):
    with fake_client(monkeypatch, {}) as client:
        with pytest.raises(PabloError, match="tool exploded"):
            client.call_tool("broken", {})


def test_timeout_kills_child(monkeypatch):
    with fake_client(monkeypatch, {}, timeout=1) as client:
        with pytest.raises(PabloError, match="timed out"):
            client.call_tool("sleepy", {})
    assert client._proc.poll() is not None  # child was killed


def make_fake_node(tmp_path, version: str) -> Path:
    bin_dir = tmp_path / "versions" / "node" / f"v{version}" / "bin"
    bin_dir.mkdir(parents=True)
    (bin_dir / "npx").write_text("#!/bin/sh\n")
    (bin_dir / "npx").chmod(0o755)
    (bin_dir / "node").write_text(f"#!/bin/sh\necho v{version}\n")
    (bin_dir / "node").chmod(0o755)
    return bin_dir / "node"


def test_nvm_which_via_fake_nvm_sh(tmp_path, monkeypatch):
    node = make_fake_node(tmp_path, "24.14.1")
    nvm_dir = tmp_path / "nvm"
    nvm_dir.mkdir()
    (nvm_dir / "nvm.sh").write_text(
        'nvm() { if [ "$1" = which ]; then echo "$FAKE_NODE"; fi; }\n'
    )
    monkeypatch.setenv("NVM_DIR", str(nvm_dir))
    monkeypatch.setenv("FAKE_NODE", str(node))
    assert mcpclient._nvm_which("24") == str(node)


def test_npx_path_uses_nvmrc_spec(tmp_path, monkeypatch):
    node = make_fake_node(tmp_path, "24.14.1")
    seen = []

    def fake_nvm_which(spec):
        seen.append(spec)
        return str(node)

    monkeypatch.setattr(mcpclient, "_nvm_dir", lambda: tmp_path / "nvm")
    monkeypatch.setattr(mcpclient, "_nvm_which", fake_nvm_which)
    monkeypatch.setattr(mcpclient, "nvmrc_spec", lambda: "24")
    npx = mcpclient.npx_path()
    assert seen == ["24"]
    assert npx == str(node.parent / "npx")


def test_npx_path_rejects_old_nvm_node(tmp_path, monkeypatch):
    node = make_fake_node(tmp_path, "16.17.1")
    monkeypatch.setattr(mcpclient, "_nvm_dir", lambda: tmp_path / "nvm")
    monkeypatch.setattr(mcpclient, "_nvm_which", lambda spec: str(node))
    monkeypatch.setattr(mcpclient, "nvmrc_spec", lambda: "16")
    with pytest.raises(PabloError, match="18"):
        mcpclient.npx_path()


def test_npx_path_unresolvable_spec_errors(tmp_path, monkeypatch):
    monkeypatch.setattr(mcpclient, "_nvm_dir", lambda: tmp_path / "nvm")
    monkeypatch.setattr(mcpclient, "_nvm_which", lambda spec: None)
    monkeypatch.setattr(mcpclient, "nvmrc_spec", lambda: "24")
    with pytest.raises(PabloError, match="nvm install 24"):
        mcpclient.npx_path()


def test_npx_path_falls_back_to_path_without_nvm(tmp_path, monkeypatch):
    node = make_fake_node(tmp_path, "24.14.1")
    monkeypatch.setattr(mcpclient, "_nvm_dir", lambda: None)
    monkeypatch.setattr(
        mcpclient.shutil, "which", lambda name: str(node.parent / "npx")
    )
    assert mcpclient.npx_path() == str(node.parent / "npx")


def test_early_exit_surfaces_stderr(monkeypatch):
    client = McpClient(
        argv=["sh", "-c", "echo 'SyntaxError: nope' >&2; exit 1"], timeout_s=5
    )
    with pytest.raises(PabloError, match="SyntaxError"):
        client.__enter__()


def test_is_transient_error_matches_known_markers():
    for marker in mcpclient.TRANSIENT_MARKERS:
        assert mcpclient.is_transient_error(f"boom: {marker} happened")
    assert not mcpclient.is_transient_error("jira mcp tool x errored: bad request")


class _FakeSession:
    """Stand-in for McpClient used by the retry-helper tests."""

    def __init__(self, behaviors, calls):
        self._behaviors = behaviors
        self._calls = calls

    def __call__(self, argv=None, timeout_s=60):
        self._calls.append(1)
        return self

    def __enter__(self):
        behavior = self._behaviors[len(self._calls) - 1]
        if isinstance(behavior, Exception):
            raise behavior
        return self

    def __exit__(self, *exc):
        return False

    def call_tool(self, name, arguments):
        return self._behaviors[len(self._calls) - 1]


def test_call_tool_with_retry_recovers_from_transient_failure(monkeypatch):
    calls = []
    behaviors = [PabloError("ConnectTimeoutError: nope"), {"ok": True}]
    monkeypatch.setattr(mcpclient, "McpClient", _FakeSession(behaviors, calls))
    monkeypatch.setattr(mcpclient.time, "sleep", lambda s: None)
    result = mcpclient.call_tool_with_retry("x", {})
    assert result == {"ok": True}
    assert len(calls) == 2


def test_call_tool_with_retry_gives_up_after_max_attempts(monkeypatch):
    calls = []
    behaviors = [PabloError("ETIMEDOUT: nope")] * mcpclient.RETRY_ATTEMPTS
    monkeypatch.setattr(mcpclient, "McpClient", _FakeSession(behaviors, calls))
    monkeypatch.setattr(mcpclient.time, "sleep", lambda s: None)
    with pytest.raises(PabloError, match="ETIMEDOUT"):
        mcpclient.call_tool_with_retry("x", {})
    assert len(calls) == mcpclient.RETRY_ATTEMPTS


def test_call_tool_with_retry_does_not_retry_non_transient(monkeypatch):
    calls = []
    behaviors = [PabloError("jira mcp tool x errored: bad request")]
    monkeypatch.setattr(mcpclient, "McpClient", _FakeSession(behaviors, calls))
    monkeypatch.setattr(mcpclient.time, "sleep", lambda s: None)
    with pytest.raises(PabloError, match="bad request"):
        mcpclient.call_tool_with_retry("x", {})
    assert len(calls) == 1


def test_auth_cache_present_globs_tokens(tmp_path, monkeypatch):
    monkeypatch.setattr(mcpclient.Path, "home", classmethod(lambda cls: tmp_path))
    assert auth_cache_present() is False
    tokens = tmp_path / ".mcp-auth" / "mcp-remote-0.1" / "abc_tokens.json"
    tokens.parent.mkdir(parents=True)
    tokens.write_text("{}")
    assert auth_cache_present() is True
