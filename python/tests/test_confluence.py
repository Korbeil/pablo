import json
from pathlib import Path

import pytest

from pablo import PabloError, confluence
from pablo.config import ProjectConfig


PAGE_JSON = {
    "id": "36307094",
    "title": "PIM —Accueil",
    "_links": {
        "base": "https://sezane.atlassian.net/wiki",
        "webui": "/spaces/PIM/overview",
    },
    "body": {
        "storage": {
            "representation": "storage",
            "value": "<p>Espace dédié aux spécifications…</p>",
        }
    },
}


def cfg(tmp_path: Path, site: str | None = "sezane.atlassian.net",
        space: str | None = "PIM") -> ProjectConfig:
    return ProjectConfig(
        name="sezane-pim",
        type="work",
        repo_path=tmp_path,
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="jira",
        identity="baptiste@example.com",
        project_key="PIM",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
        ci_ignore_checks=[],
        site=site,
        confluence_space=space,
    )


def patch_run_cli(monkeypatch, payload=PAGE_JSON, calls=None):
    def fake_run_cli(argv, **kwargs):
        if calls is not None:
            calls.append(argv)
        return json.dumps(payload)
    monkeypatch.setattr(confluence, "run_cli", fake_run_cli)


def test_match_url_extracts_id_from_pages_path():
    url = "https://sezane.atlassian.net/wiki/spaces/PIM/pages/36307094/PIM+Home"
    assert confluence.match_url(url) == "36307094"


def test_match_url_extracts_id_from_query():
    url = "https://sezane.atlassian.net/wiki?pageId=36307094"
    assert confluence.match_url(url) == "36307094"


def test_match_url_returns_none_for_non_confluence():
    assert confluence.match_url("https://sezane.atlassian.net/browse/PIM-1") is None
    assert confluence.match_url("not a url") is None


def test_fetch_by_bare_id(tmp_path, monkeypatch):
    calls = []
    patch_run_cli(monkeypatch, calls=calls)
    page = confluence.fetch("36307094", cfg(tmp_path))
    assert page.id == "36307094"
    assert page.title == "PIM —Accueil"
    assert page.url == "https://sezane.atlassian.net/wiki/spaces/PIM/overview"
    assert "Espace dédié" in page.body
    argv = calls[0]
    assert argv[0] == "acli"
    assert "page" in argv and "view" in argv
    assert "--id" in argv and argv[argv.index("--id") + 1] == "36307094"
    assert "--json" in argv
    assert "--body-format" in argv and argv[argv.index("--body-format") + 1] == "storage"


def test_fetch_by_url_parses_id(tmp_path, monkeypatch):
    calls = []
    patch_run_cli(monkeypatch, calls=calls)
    page = confluence.fetch(
        "https://sezane.atlassian.net/wiki/spaces/PIM/pages/36307094/PIM+Home",
        cfg(tmp_path),
    )
    assert argv_id_arg(calls[0]) == "36307094"
    assert page.id == "36307094"


def argv_id_arg(argv):
    return argv[argv.index("--id") + 1]


def test_fetch_rejects_empty(tmp_path):
    with pytest.raises(PabloError, match="no page id"):
        confluence.fetch("   ", cfg(tmp_path))


def test_fetch_rejects_unparseable_url(tmp_path):
    with pytest.raises(PabloError, match="not a page id or Confluence URL"):
        confluence.fetch("https://sezane.atlassian.net/browse/PIM-1", cfg(tmp_path))


def test_fetch_surfaces_unexpected_response(tmp_path, monkeypatch):
    patch_run_cli(monkeypatch, payload={"nope": True})
    with pytest.raises(PabloError, match="unexpected acli response"):
        confluence.fetch("36307094", cfg(tmp_path))


def test_fetch_handles_missing_body(tmp_path, monkeypatch):
    payload = {**PAGE_JSON, "body": None}
    patch_run_cli(monkeypatch, payload=payload)
    page = confluence.fetch("36307094", cfg(tmp_path))
    assert page.body == ""


def test_cli_name_and_auth_check():
    assert confluence.cli_name() == "acli"
    assert confluence.auth_check_cmd() == ["acli", "confluence", "auth", "status"]