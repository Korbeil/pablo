from datetime import datetime, timezone
from pathlib import Path

import pytest

from pablo.config import ProjectConfig
from pablo.model import Task
from pablo.providers import get_provider
from pablo.providers import jira as jira_mod


def cfg(tmp_path: Path, site: str | None = "acme.atlassian.net") -> ProjectConfig:
    return ProjectConfig(
        name="acme-pim",
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
        failure_signal="A FIX",
        bot_whitelist=[],
        ci_ignore_checks=[],
        site=site,
    )


RESOURCES = [
    {"id": "cloud-other", "url": "https://other.atlassian.net", "name": "other"},
    {"id": "cloud-acme", "url": "https://acme.atlassian.net", "name": "acme"},
]

ISSUE = {
    "key": "XXX-123",
    "fields": {
        "summary": "Fix product import",
        "status": {"name": "In Progress"},
    },
}

ISSUE_WITH_CHANGELOG = {
    **ISSUE,
    "changelog": {
        "histories": [
            {
                "created": "2026-07-20T10:00:00.000+0000",
                "items": [{"field": "status", "toString": "A FIX"}],
            },
            {
                "created": "2026-07-21T10:00:00.000+0000",
                "items": [{"field": "assignee", "toString": "someone"}],
            },
            {
                "created": "2026-07-19T09:00:00.000+0000",
                "items": [{"field": "status", "toString": "A FIX"}],
            },
        ]
    },
}


@pytest.fixture(autouse=True)
def _isolated_cache_dir(tmp_path, monkeypatch):
    monkeypatch.setenv("PABLO_CACHE_DIR", str(tmp_path / "cache"))


@pytest.fixture
def provider(monkeypatch):
    provider = get_provider("jira")
    return provider


def patch_call(monkeypatch, responses: dict, calls: list | None = None):
    def fake_call(tool, args):
        if calls is not None:
            calls.append((tool, args))
        if tool not in responses:
            raise AssertionError(f"unexpected MCP call: {tool} {args}")
        return responses[tool]

    monkeypatch.setattr(jira_mod, "call", fake_call)


def test_match_url(provider, tmp_path):
    c = cfg(tmp_path)
    assert provider.match_url("https://acme.atlassian.net/browse/XXX-123", c) == "XXX-123"
    assert provider.match_url("https://acme.atlassian.net/browse/XXX-9", c) is None
    assert provider.match_url("https://github.com/a/b/issues/1", c) is None


def test_cloud_id_matches_site(provider, tmp_path, monkeypatch):
    patch_call(monkeypatch, {"getAccessibleAtlassianResources": RESOURCES})
    assert provider._cloud_id(cfg(tmp_path)) == "cloud-acme"


def test_cloud_id_defaults_first(provider, tmp_path, monkeypatch):
    patch_call(monkeypatch, {"getAccessibleAtlassianResources": RESOURCES})
    assert provider._cloud_id(cfg(tmp_path, site=None)) == "cloud-other"


def test_cloud_id_cached(provider, tmp_path, monkeypatch):
    calls = []
    patch_call(monkeypatch, {"getAccessibleAtlassianResources": RESOURCES}, calls)
    c = cfg(tmp_path)
    provider._cloud_id(c)
    provider._cloud_id(c)
    assert len(calls) == 1


def test_get_issue_parses_fields(provider, tmp_path, monkeypatch):
    patch_call(
        monkeypatch,
        {"getAccessibleAtlassianResources": RESOURCES, "getJiraIssue": ISSUE},
    )
    issue = provider.get_issue("XXX-123", cfg(tmp_path))
    assert issue.key == "XXX-123"
    assert issue.title == "Fix product import"
    assert issue.status == "In Progress"
    assert issue.project_key == "XXX"
    assert "browse/XXX-123" in issue.url


def test_list_assigned_uses_jql_currentuser(provider, tmp_path, monkeypatch):
    calls = []
    patch_call(
        monkeypatch,
        {
            "getAccessibleAtlassianResources": RESOURCES,
            "searchJiraIssuesUsingJql": {"issues": [ISSUE]},
        },
        calls,
    )
    issues = provider.list_assigned(cfg(tmp_path))
    assert [issue.key for issue in issues] == ["XXX-123"]
    tool, args = calls[-1]
    assert tool == "searchJiraIssuesUsingJql"
    assert args["cloudId"] == "cloud-acme"
    assert "project = XXX" in args["jql"]
    assert "assignee = currentUser()" in args["jql"]


def test_issue_status(provider, tmp_path, monkeypatch):
    patch_call(
        monkeypatch,
        {"getAccessibleAtlassianResources": RESOURCES, "getJiraIssue": ISSUE},
    )
    assert provider.issue_status("XXX-123", cfg(tmp_path)) == "In Progress"


def make_task(tmp_path, provider, monkeypatch):
    patch_call(
        monkeypatch,
        {"getAccessibleAtlassianResources": RESOURCES, "getJiraIssue": ISSUE},
    )
    task = Task(project="acme-pim", branch="xxx-123", worktree_path=tmp_path,
                state="needs-testing")
    task.issue = provider.get_issue("XXX-123", cfg(tmp_path))
    return task


def test_failure_signal_from_changelog_when_present(provider, tmp_path, monkeypatch):
    task = make_task(tmp_path, provider, monkeypatch)
    patch_call(
        monkeypatch,
        {"getAccessibleAtlassianResources": RESOURCES,
         "getJiraIssue": ISSUE_WITH_CHANGELOG},
    )
    stamps = provider.failure_signal_events(task, cfg(tmp_path))
    assert len(stamps) == 2
    assert stamps == sorted(stamps)
    assert stamps[-1] == datetime(2026, 7, 20, 10, 0, tzinfo=timezone.utc)


def test_failure_signal_empty_without_changelog(provider, tmp_path, monkeypatch):
    task = make_task(tmp_path, provider, monkeypatch)
    patch_call(
        monkeypatch,
        {"getAccessibleAtlassianResources": RESOURCES, "getJiraIssue": ISSUE},
    )
    assert provider.failure_signal_events(task, cfg(tmp_path)) == []


def test_signal_via_status_flag(provider):
    assert provider.signal_via_status is True


def test_cloud_id_persisted_across_provider_instances(tmp_path, monkeypatch):
    calls = []
    patch_call(monkeypatch, {"getAccessibleAtlassianResources": RESOURCES}, calls)
    c = cfg(tmp_path)

    get_provider("jira")._cloud_id(c)
    assert len(calls) == 1

    # A brand-new instance (as listing.py/poller.py build per call) must
    # hit the on-disk cache instead of re-resolving via MCP.
    assert get_provider("jira")._cloud_id(c) == "cloud-acme"
    assert len(calls) == 1


def test_cloud_id_disk_cache_deleted_falls_back_to_resolve(tmp_path, monkeypatch):
    calls = []
    patch_call(monkeypatch, {"getAccessibleAtlassianResources": RESOURCES}, calls)
    c = cfg(tmp_path)
    get_provider("jira")._cloud_id(c)
    assert len(calls) == 1

    from pablo.providers.jira import _cloud_id_cache_path

    _cloud_id_cache_path().unlink()
    assert get_provider("jira")._cloud_id(c) == "cloud-acme"
    assert len(calls) == 2


def test_call_delegates_to_retry_helper(monkeypatch):
    from pablo import mcpclient

    calls = []

    def fake_retry(tool, args, **kwargs):
        calls.append((tool, args))
        return {"stubbed": True}

    monkeypatch.setattr(mcpclient, "call_tool_with_retry", fake_retry)
    assert jira_mod.call("x", {"a": 1}) == {"stubbed": True}
    assert calls == [("x", {"a": 1})]
