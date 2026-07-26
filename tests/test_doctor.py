from pathlib import Path

import pytest

from pablo import cli, doctor
from pablo.config import ProjectConfig


def make_cfg(tmp_path: Path, name: str, provider: str) -> ProjectConfig:
    return ProjectConfig(
        name=name,
        type="work",
        repo_path=tmp_path / name,
        primary_branch="main",
        worktrees_root=tmp_path / "wt" / name,
        provider=provider,
        identity="user",
        project_key="PR",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
    )


def test_required_set_without_jira_linear(tmp_path):
    projects = {"a": make_cfg(tmp_path, "a", "github")}
    assert doctor.required_clis(projects) == ["gh", "opencode", "orca"]


def test_required_includes_jira_and_linear_when_used(tmp_path):
    projects = {
        "a": make_cfg(tmp_path, "a", "jira"),
        "b": make_cfg(tmp_path, "b", "linear"),
    }
    assert doctor.required_clis(projects) == ["gh", "jira", "linear", "opencode", "orca"]


def test_missing_cli_reported_not_installed(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda cli_name: None)
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    assert all(not r.installed and not r.ok for r in results)
    gh = next(r for r in results if r.cli == "gh")
    assert "not installed" in gh.detail


def test_auth_failure_surfaces_cli_message_and_hint(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda cli_name: f"/usr/bin/{cli_name}")

    def fake_probe(argv):
        return 1, "You are not logged into any GitHub hosts"

    monkeypatch.setattr(doctor, "_probe", fake_probe)
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    gh = next(r for r in results if r.cli == "gh")
    assert gh.installed and not gh.authenticated and not gh.ok
    assert "not logged in" in gh.detail.lower()
    assert "gh auth login" in gh.hint


def test_all_green(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda cli_name: f"/usr/bin/{cli_name}")
    monkeypatch.setattr(doctor, "_probe", lambda argv: (0, "ok"))
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    assert all(r.ok for r in results)


def test_cli_doctor_exit_codes(tmp_path, monkeypatch, capsys):
    projects = {"a": make_cfg(tmp_path, "a", "github")}
    monkeypatch.setattr(cli, "load_projects", lambda: projects)
    monkeypatch.setattr(doctor.shutil, "which", lambda cli_name: f"/usr/bin/{cli_name}")
    monkeypatch.setattr(doctor, "_probe", lambda argv: (0, "ok"))
    assert cli.main(["doctor"]) == 0
    assert "✅" in capsys.readouterr().out

    monkeypatch.setattr(doctor, "_probe", lambda argv: (1, "login please"))
    assert cli.main(["doctor"]) == 1
    assert "❌" in capsys.readouterr().out
