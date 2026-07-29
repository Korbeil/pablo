import json
from pathlib import Path

import pytest

from pablo.config import ProjectConfig
from pablo.model import Task
from pablo.providers import get_provider
from pablo.providers import jira as jira_mod

ISSUE_JSON = {
    "key": "XXX-123",
    "fields": {
        "summary": "Fix product import",
        "status": {"name": "In Progress"},
    },
}

ISSUE_JSON_DIFF_STATUS = {
    "key": "XXX-123",
    "fields": {
        "summary": "Fix product import",
        "status": {"name": "A FIX"},
    },
}


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


@pytest.fixture
def provider():
    return get_provider("jira")


def patch_run_cli(monkeypatch, *, view=ISSUE_JSON, search=None, calls=None) -> None:
    """Fake `providers.run_cli`, dispatching by subcommand.

    `view`/`search`: JSON-decodable payloads to return for the matching
    acli call. `search` defaults to [view] when unset. acli returns the
    raw JSON object for `view` and a JSON list for `search`; run_cli yields
    the *string* output that the provider then json.loads — so we encode
    the payloads here.
    """
    search = search if search is not None else [view]

    def fake_run_cli(argv, **kwargs):
        if calls is not None:
            calls.append(argv)
        if "search" in argv:
            return json.dumps(search)
        if "view" in argv:
            return json.dumps(view)
        raise AssertionError(f"unexpected acli argv: {argv}")

    monkeypatch.setattr(jira_mod, "run_cli", fake_run_cli)


def test_match_url(provider, tmp_path):
    c = cfg(tmp_path)
    assert provider.match_url("https://acme.atlassian.net/browse/XXX-123", c) == "XXX-123"
    assert provider.match_url("https://acme.atlassian.net/browse/YYY-9", c) is None
    assert provider.match_url("https://github.com/a/b/issues/1", c) is None


def test_get_issue_parses_fields(provider, tmp_path, monkeypatch):
    patch_run_cli(monkeypatch)
    issue = provider.get_issue("XXX-123", cfg(tmp_path))
    assert issue.key == "XXX-123"
    assert issue.title == "Fix product import"
    assert issue.status == "In Progress"
    assert issue.project_key == "XXX"
    assert "browse/XXX-123" in issue.url


def test_list_assigned_uses_jql_currentuser(provider, tmp_path, monkeypatch):
    calls = []
    patch_run_cli(monkeypatch, search=[ISSUE_JSON], calls=calls)
    issues = provider.list_assigned(cfg(tmp_path))
    assert [issue.key for issue in issues] == ["XXX-123"]
    argv = calls[-1]
    assert "search" in argv
    jql = argv[argv.index("--jql") + 1]
    assert "project = XXX" in jql
    assert "assignee = currentUser()" in jql
    assert argv[argv.index("--limit") + 1] == "50"


def test_issue_status(provider, tmp_path, monkeypatch):
    patch_run_cli(monkeypatch)
    assert provider.issue_status("XXX-123", cfg(tmp_path)) == "In Progress"


def make_task(tmp_path, provider, monkeypatch):
    patch_run_cli(monkeypatch)
    task = Task(project="acme-pim", branch="xxx-123", worktree_path=tmp_path,
                state="needs-testing")
    task.issue = provider.get_issue("XXX-123", cfg(tmp_path))
    return task


def test_failure_signal_empty_when_changelog_unavailable(provider, tmp_path, monkeypatch):
    # acli never returns a changelog → the provider always yields [] and the
    # poller's observed-transition fallback (signal_via_status) does the work.
    task = make_task(tmp_path, provider, monkeypatch)
    assert provider.failure_signal_events(task, cfg(tmp_path)) == []


def test_failure_signal_empty_even_when_status_matches_signal(provider, tmp_path, monkeypatch):
    patch_run_cli(monkeypatch, view=ISSUE_JSON_DIFF_STATUS)
    task = make_task(tmp_path, provider, monkeypatch)
    # Status transition detection is the poller's job; the provider never
    # synthesizes stamps from the current status alone.
    assert provider.failure_signal_events(task, cfg(tmp_path)) == []


def test_signal_via_status_flag(provider):
    assert provider.signal_via_status is True


def test_cli_name_is_acli(provider):
    assert provider.cli_name() == "acli"


def test_auth_check_cmd(provider):
    assert provider.auth_check_cmd() == ["acli", "jira", "auth", "status"]