from pathlib import Path

from pablo import cli, doctor
from pablo.config import ProjectConfig


def make_cfg(tmp_path: Path, name: str, provider: str,
             confluence_space: str | None = None) -> ProjectConfig:
    return ProjectConfig(
        name=name,
        type="work",
        repo_path=tmp_path / name,
        primary_branch="main",
        worktrees_root=tmp_path / "wt" / name,
        provider=provider,
        identity="korbeil",
        project_key="PR",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
        ci_ignore_checks=[],
        confluence_space=confluence_space,
    )


def test_required_set_without_jira_linear(tmp_path):
    projects = {"a": make_cfg(tmp_path, "a", "github")}
    assert doctor.required_clis(projects) == ["gh", "opencode", "orca"]


def test_required_includes_acli_for_jira_projects(tmp_path):
    projects = {
        "a": make_cfg(tmp_path, "a", "jira"),
        "b": make_cfg(tmp_path, "b", "linear"),
    }
    assert doctor.required_clis(projects) == [
        "acli", "gh", "linear", "opencode", "orca",
    ]


def test_required_adds_acli_confluence_only_when_space_configured(tmp_path):
    projects = {
        "a": make_cfg(tmp_path, "a", "jira", confluence_space="PIM"),
        "b": make_cfg(tmp_path, "b", "jira"),
    }
    assert "acli" in doctor.required_clis(projects)
    assert "acli-confluence" in doctor.required_clis(projects)
    # Without any confluence space configured, acli-confluence is NOT required.
    projects = {"a": make_cfg(tmp_path, "a", "jira")}
    assert "acli-confluence" not in doctor.required_clis(projects)


def test_missing_cli_reported_not_installed(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda name: None)
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    assert all(not r.installed and not r.ok for r in results)
    gh = next(r for r in results if r.cli == "gh")
    assert "not installed" in gh.detail


def test_auth_failure_surfaces_cli_message_and_hint(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda name: f"/usr/bin/{name}")

    def fake_probe(argv):
        return 1, "You are not logged into any GitHub hosts"

    monkeypatch.setattr(doctor, "_probe", fake_probe)
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    gh = next(r for r in results if r.cli == "gh")
    assert gh.installed and not gh.authenticated and not gh.ok
    assert "not logged in" in gh.detail.lower()
    assert "gh auth login" in gh.hint


def test_all_green(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda name: f"/usr/bin/{name}")
    monkeypatch.setattr(doctor, "_probe", lambda argv: (0, "ok"))
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    assert all(r.ok for r in results)


def acli_jira_results(tmp_path, monkeypatch, confluence_space=None):
    monkeypatch.setattr(doctor.shutil, "which", lambda name: f"/usr/bin/{name}")
    monkeypatch.setattr(doctor, "_probe", _probe_dispatch)
    return doctor.check_all(
        {"a": make_cfg(tmp_path, "a", "jira", confluence_space=confluence_space)}
    )


def _probe_dispatch(argv):
    # acli's two probes report different statuses; surface the confluence
    # one distinctly so the jira-ok / confluence-fail case is testable.
    if argv == ["acli", "confluence", "auth", "status"]:
        return 1, "not authenticated: run acli confluence auth login"
    if argv == ["acli", "jira", "auth", "status"]:
        return 0, "✓ Authenticated\n  Email: baptiste@example.com"
    return 0, "ok"


def test_jira_probe_failure_surfaces_acli_hint(tmp_path, monkeypatch):
    monkeypatch.setattr(doctor.shutil, "which", lambda name: f"/usr/bin/{name}")

    def boom(argv):
        return 1, "Error: not authenticated"

    monkeypatch.setattr(doctor, "_probe", boom)
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "jira")})
    check = next(r for r in results if r.cli == "acli")
    assert check.installed and not check.authenticated and not check.ok
    assert "acli auth login" in check.hint
    assert "not authenticated" in check.detail


def test_jira_probe_ok(tmp_path, monkeypatch):
    results = acli_jira_results(tmp_path, monkeypatch)
    check = next(r for r in results if r.cli == "acli")
    assert check.ok
    assert "Authenticated" in check.detail


def test_confluence_probe_reported_separately(tmp_path, monkeypatch):
    results = acli_jira_results(tmp_path, monkeypatch, confluence_space="PIM")
    acli_jira = next(r for r in results if r.cli == "acli")
    acli_conf = next(r for r in results if r.cli == "acli-confluence")
    assert acli_jira.ok
    assert not acli_conf.ok
    assert "confluence auth login" in acli_conf.hint


def test_probe_timeout_reported_as_failure(tmp_path, monkeypatch):
    import subprocess

    monkeypatch.setattr(doctor.shutil, "which", lambda name: f"/usr/bin/{name}")

    def hanging_run(argv, **kwargs):
        raise subprocess.TimeoutExpired(argv, kwargs.get("timeout", 30))

    monkeypatch.setattr(doctor.subprocess, "run", hanging_run)
    results = doctor.check_all({"a": make_cfg(tmp_path, "a", "github")})
    gh = next(r for r in results if r.cli == "gh")
    assert not gh.ok
    assert "timed out" in gh.detail


def test_cli_doctor_exit_codes(tmp_path, monkeypatch, capsys):
    projects = {"a": make_cfg(tmp_path, "a", "github")}
    monkeypatch.setattr(cli, "load_projects", lambda: projects)
    monkeypatch.setattr(doctor.shutil, "which", lambda name: f"/usr/bin/{name}")
    monkeypatch.setattr(doctor, "_probe", lambda argv: (0, "ok"))
    assert cli.main(["doctor"]) == 0
    assert "✅" in capsys.readouterr().out

    monkeypatch.setattr(doctor, "_probe", lambda argv: (1, "login please"))
    assert cli.main(["doctor"]) == 1
    assert "❌" in capsys.readouterr().out