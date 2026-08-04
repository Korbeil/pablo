import json
from datetime import datetime, timezone
from pathlib import Path

import pytest

from pablo.config import ProjectConfig
from pablo.model import Task
from pablo.providers import get_provider
from pablo.providers import linear as linear_mod


def cfg(tmp_path: Path) -> ProjectConfig:
    return ProjectConfig(
        name="stably",
        type="personal",
        repo_path=tmp_path,
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="linear",
        identity="korbeil",
        project_key="STA",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal="Testing Failed",
        bot_whitelist=[],
        ci_ignore_checks=[],
    )


@pytest.fixture
def provider():
    return get_provider("linear")


def patch_cli(monkeypatch, responses: dict[str, str]):
    def fake_run_cli(argv, *, check=True):
        joined = " ".join(argv)
        for key, out in responses.items():
            if key in joined:
                return out
        raise AssertionError(f"unexpected CLI call: {joined}")

    monkeypatch.setattr(linear_mod, "run_cli", fake_run_cli)


def test_match_url(provider, tmp_path):
    c = cfg(tmp_path)
    assert (
        provider.match_url(
            "https://linear.app/stably/issue/STA-335/test-issue", c
        )
        == "STA-335"
    )
    assert provider.match_url("https://linear.app/stably/issue/OTH-1/x", c) is None
    assert provider.match_url("https://example.com", c) is None


ISSUE_JSON = {
    "identifier": "STA-335",
    "title": "Test issue",
    "url": "https://linear.app/stably/issue/STA-335/test-issue",
    "state": {"name": "In Progress"},
    "history": [
        {"createdAt": "2026-07-20T10:00:00.000Z", "toState": {"name": "Testing Failed"}},
        {"createdAt": "2026-07-21T10:00:00.000Z", "toState": {"name": "Done"}},
        {"createdAt": "2026-07-19T10:00:00.000Z", "toState": {"name": "Testing Failed"}},
    ],
}


def test_get_issue_parses(provider, tmp_path, monkeypatch):
    patch_cli(monkeypatch, {"issue view STA-335": json.dumps(ISSUE_JSON)})
    issue = provider.get_issue("STA-335", cfg(tmp_path))
    assert issue.key == "STA-335"
    assert issue.title == "Test issue"
    assert issue.project_key == "STA"


def test_issue_status(provider, tmp_path, monkeypatch):
    patch_cli(monkeypatch, {"issue view STA-335": json.dumps(ISSUE_JSON)})
    assert provider.issue_status("STA-335", cfg(tmp_path)) == "In Progress"


def test_list_assigned_parses(provider, tmp_path, monkeypatch):
    patch_cli(
        monkeypatch,
        {
            "issue list": json.dumps(
                [
                    {"identifier": "STA-1", "title": "A", "url": "u1",
                     "state": {"name": "Todo"}},
                    {"identifier": "STA-2", "title": "B", "url": "u2",
                     "state": {"name": "Done"}},
                ]
            )
        },
    )
    issues = provider.list_assigned(cfg(tmp_path))
    assert [i.key for i in issues] == ["STA-1", "STA-2"]


def test_failure_signal_events(provider, tmp_path, monkeypatch):
    patch_cli(monkeypatch, {"issue view STA-335": json.dumps(ISSUE_JSON)})
    task = Task(
        project="stably",
        branch="sta-335",
        worktree_path=tmp_path,
        state="needs-testing",
    )
    task.issue = provider.get_issue("STA-335", cfg(tmp_path))
    stamps = provider.failure_signal_events(task, cfg(tmp_path))
    assert len(stamps) == 2
    assert stamps == sorted(stamps)
    assert stamps[-1] == datetime(2026, 7, 20, 10, 0, tzinfo=timezone.utc)
