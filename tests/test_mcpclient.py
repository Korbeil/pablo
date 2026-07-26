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


def make_fake_node_tree(tmp_path, versions):
    for version in versions:
        bin_dir = tmp_path / ".nvm" / "versions" / "node" / f"v{version}" / "bin"
        bin_dir.mkdir(parents=True)
        (bin_dir / "npx").write_text("#!/bin/sh\n")
        (bin_dir / "npx").chmod(0o755)
        (bin_dir / "node").write_text(f"#!/bin/sh\necho v{version}\n")
        (bin_dir / "node").chmod(0o755)


def test_npx_prefers_newest_node(tmp_path, monkeypatch):
    make_fake_node_tree(tmp_path, ["16.17.1", "24.14.1"])
    monkeypatch.setattr(mcpclient.Path, "home", classmethod(lambda cls: tmp_path))
    # PATH resolution finds the old one (as under the systemd user manager)
    old_npx = tmp_path / ".nvm/versions/node/v16.17.1/bin/npx"
    monkeypatch.setattr(mcpclient.shutil, "which", lambda name: str(old_npx))
    assert "v24.14.1" in mcpclient.npx_path()


def test_npx_rejects_node_too_old(tmp_path, monkeypatch):
    make_fake_node_tree(tmp_path, ["16.17.1"])
    monkeypatch.setattr(mcpclient.Path, "home", classmethod(lambda cls: tmp_path))
    old_npx = tmp_path / ".nvm/versions/node/v16.17.1/bin/npx"
    monkeypatch.setattr(mcpclient.shutil, "which", lambda name: str(old_npx))
    with pytest.raises(PabloError, match="18"):
        mcpclient.npx_path()


def test_early_exit_surfaces_stderr(monkeypatch):
    client = McpClient(
        argv=["sh", "-c", "echo 'SyntaxError: nope' >&2; exit 1"], timeout_s=5
    )
    with pytest.raises(PabloError, match="SyntaxError"):
        client.__enter__()


def test_auth_cache_present_globs_tokens(tmp_path, monkeypatch):
    monkeypatch.setattr(mcpclient.Path, "home", classmethod(lambda cls: tmp_path))
    assert auth_cache_present() is False
    tokens = tmp_path / ".mcp-auth" / "mcp-remote-0.1" / "abc_tokens.json"
    tokens.parent.mkdir(parents=True)
    tokens.write_text("{}")
    assert auth_cache_present() is True
