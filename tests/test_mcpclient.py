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


def test_auth_cache_present_globs_tokens(tmp_path, monkeypatch):
    monkeypatch.setattr(mcpclient.Path, "home", classmethod(lambda cls: tmp_path))
    assert auth_cache_present() is False
    tokens = tmp_path / ".mcp-auth" / "mcp-remote-0.1" / "abc_tokens.json"
    tokens.parent.mkdir(parents=True)
    tokens.write_text("{}")
    assert auth_cache_present() is True
