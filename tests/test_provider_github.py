import json
from datetime import datetime, timezone
from pathlib import Path

import pytest

from pablo import PabloError
from pablo.config import ProjectConfig
from pablo.model import Task
from pablo.providers import get_provider, run_cli
from pablo.providers import github as gh_mod


def cfg(tmp_path: Path) -> ProjectConfig:
    return ProjectConfig(
        name="wallet-kit",
        type="open-source",
        repo_path=tmp_path,
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="github",
        identity="user",
        project_key="WK",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal="qa-failed",
        bot_whitelist=[],
        ci_ignore_checks=[],
    )


@pytest.fixture
def provider():
    return get_provider("github")


def patch_cli(monkeypatch, responses: dict[str, str]):
    """Map a substring of the argv to canned stdout."""

    def fake_run_cli(argv, *, check=True, timeout=None):
        joined = " ".join(argv)
        for key, out in responses.items():
            if key in joined:
                return out
        raise AssertionError(f"unexpected CLI call: {joined}")

    monkeypatch.setattr(gh_mod, "run_cli", fake_run_cli)
    return fake_run_cli


def test_get_provider_unknown():
    with pytest.raises(PabloError, match="unknown provider"):
        get_provider("gitlab")


def test_match_url_same_repo(provider, tmp_path, monkeypatch):
    monkeypatch.setattr(
        gh_mod, "origin_url", lambda repo: "git@github.com:acme/wallet-kit.git"
    )
    c = cfg(tmp_path)
    assert provider.match_url("https://github.com/acme/wallet-kit/issues/45", c) == "45"


def test_match_url_other_repo_returns_none(provider, tmp_path, monkeypatch):
    monkeypatch.setattr(
        gh_mod, "origin_url", lambda repo: "https://github.com/acme/wallet-kit.git"
    )
    c = cfg(tmp_path)
    assert provider.match_url("https://github.com/acme/other/issues/45", c) is None
    assert provider.match_url("https://example.com/x", c) is None


def test_get_issue_parses(provider, tmp_path, monkeypatch):
    monkeypatch.setattr(
        gh_mod, "origin_url", lambda repo: "git@github.com:acme/wallet-kit.git"
    )
    patch_cli(
        monkeypatch,
        {
            "issue view 45": json.dumps(
                {
                    "number": 45,
                    "title": "Fix callback verification",
                    "state": "OPEN",
                    "url": "https://github.com/acme/wallet-kit/issues/45",
                }
            )
        },
    )
    issue = provider.get_issue("45", cfg(tmp_path))
    assert issue.key == "45"
    assert issue.title == "Fix callback verification"
    assert issue.project_key == "WK"


def test_list_assigned_parses(provider, tmp_path, monkeypatch):
    monkeypatch.setattr(
        gh_mod, "origin_url", lambda repo: "git@github.com:acme/wallet-kit.git"
    )
    patch_cli(
        monkeypatch,
        {
            "issue list": json.dumps(
                [
                    {"number": 1, "title": "A", "state": "OPEN", "url": "u1"},
                    {"number": 2, "title": "B", "state": "CLOSED", "url": "u2"},
                ]
            )
        },
    )
    issues = provider.list_assigned(cfg(tmp_path))
    assert [i.key for i in issues] == ["1", "2"]


def test_failure_signal_filters_label_and_orders(provider, tmp_path, monkeypatch):
    monkeypatch.setattr(
        gh_mod, "origin_url", lambda repo: "git@github.com:acme/wallet-kit.git"
    )
    events = [
        {"event": "labeled", "label": {"name": "qa-failed"}, "created_at": "2026-07-20T10:00:00Z"},
        {"event": "labeled", "label": {"name": "other"}, "created_at": "2026-07-21T10:00:00Z"},
        {"event": "closed", "created_at": "2026-07-22T10:00:00Z"},
        {"event": "labeled", "label": {"name": "qa-failed"}, "created_at": "2026-07-19T10:00:00Z"},
    ]
    patch_cli(monkeypatch, {"issues/7/events": json.dumps(events)})
    task = Task(project="wallet-kit", branch="wk-45", worktree_path=tmp_path,
                state="needs-testing", pr_number=7)
    stamps = provider.failure_signal_events(task, cfg(tmp_path))
    assert stamps == sorted(stamps)
    assert len(stamps) == 2
    assert stamps[-1] == datetime(2026, 7, 20, 10, 0, tzinfo=timezone.utc)


def test_run_cli_error_surfaces_stderr():
    with pytest.raises(PabloError) as exc:
        run_cli(["sh", "-c", "echo 'gh: To get started with GitHub CLI, run gh auth login' >&2; exit 4"])
    assert "gh auth login" in str(exc.value)
