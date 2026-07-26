import json
from datetime import datetime, timezone
from pathlib import Path

import pytest

from pablo.config import ProjectConfig
from pablo.model import Task
from pablo.providers import get_provider
from pablo.providers import jira as jira_mod


def cfg(tmp_path: Path) -> ProjectConfig:
    return ProjectConfig(
        name="oms",
        type="work",
        repo_path=tmp_path,
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="jira",
        identity="baptiste@example.com",
        project_key="XXX",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal="QA Failed",
        bot_whitelist=[],
    )


@pytest.fixture
def provider():
    return get_provider("jira")


def patch_cli(monkeypatch, responses: dict[str, str]):
    def fake_run_cli(argv, *, check=True):
        joined = " ".join(argv)
        for key, out in responses.items():
            if key in joined:
                return out
        raise AssertionError(f"unexpected CLI call: {joined}")

    monkeypatch.setattr(jira_mod, "run_cli", fake_run_cli)


def test_match_url(provider, tmp_path):
    c = cfg(tmp_path)
    assert (
        provider.match_url("https://acme.atlassian.net/browse/XXX-123", c) == "XXX-123"
    )
    assert provider.match_url("https://acme.atlassian.net/browse/CMS-9", c) is None
    assert provider.match_url("https://github.com/a/b/issues/1", c) is None


ISSUE_RAW = {
    "key": "XXX-123",
    "fields": {
        "summary": "Fix invoice rounding",
        "status": {"name": "In Progress"},
    },
    "changelog": {
        "histories": [
            {
                "created": "2026-07-20T10:00:00.000+0000",
                "items": [{"field": "status", "toString": "QA Failed"}],
            },
            {
                "created": "2026-07-21T10:00:00.000+0000",
                "items": [{"field": "assignee", "toString": "someone"}],
            },
            {
                "created": "2026-07-19T09:00:00.000+0000",
                "items": [{"field": "status", "toString": "QA Failed"}],
            },
        ]
    },
}


def test_get_issue_parses(provider, tmp_path, monkeypatch):
    patch_cli(monkeypatch, {"issue view XXX-123": json.dumps(ISSUE_RAW)})
    issue = provider.get_issue("XXX-123", cfg(tmp_path))
    assert issue.key == "XXX-123"
    assert issue.title == "Fix invoice rounding"
    assert issue.project_key == "XXX"
    assert "browse/XXX-123" in issue.url


def test_issue_status(provider, tmp_path, monkeypatch):
    patch_cli(monkeypatch, {"issue view XXX-123": json.dumps(ISSUE_RAW)})
    assert provider.issue_status("XXX-123", cfg(tmp_path)) == "In Progress"


def test_list_assigned_parses(provider, tmp_path, monkeypatch):
    patch_cli(
        monkeypatch,
        {
            "issue list": json.dumps(
                {
                    "issues": [
                        {"key": "XXX-1", "fields": {"summary": "A", "status": {"name": "To Do"}}},
                        {"key": "XXX-2", "fields": {"summary": "B", "status": {"name": "Done"}}},
                    ]
                }
            )
        },
    )
    issues = provider.list_assigned(cfg(tmp_path))
    assert [i.key for i in issues] == ["XXX-1", "XXX-2"]


def test_failure_signal_events_filters_status_changes(provider, tmp_path, monkeypatch):
    patch_cli(monkeypatch, {"issue view XXX-123": json.dumps(ISSUE_RAW)})
    task = Task(
        project="oms",
        branch="xxx-123",
        worktree_path=tmp_path,
        state="needs-testing",
    )
    task.issue = provider.get_issue("XXX-123", cfg(tmp_path))
    stamps = provider.failure_signal_events(task, cfg(tmp_path))
    assert len(stamps) == 2
    assert stamps == sorted(stamps)
    assert stamps[-1] == datetime(2026, 7, 20, 10, 0, tzinfo=timezone.utc)
