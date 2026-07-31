from pathlib import Path

import pytest

from pablo import agents, cli, gitrepo
from pablo.config import ProjectConfig
from pablo.model import IN_PROGRESS, Issue
from pablo.store import Store


def make_cfg(tmp_path: Path, name: str = "wallet-kit") -> ProjectConfig:
    return ProjectConfig(
        name=name,
        type="open-source",
        repo_path=tmp_path / "repo",
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="github",
        identity="korbeil",
        project_key="WK",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
        ci_ignore_checks=[],
    )


class FakeProvider:
    name = "github"

    def __init__(self, issue: Issue | None):
        self.issue = issue

    def match_url(self, url, cfg):
        if self.issue and url == self.issue.url:
            return self.issue.key
        return None

    def get_issue(self, ref, cfg):
        return self.issue


@pytest.fixture
def env(tmp_path, monkeypatch):
    monkeypatch.setenv("PABLO_STATE_DIR", str(tmp_path / "state"))
    issue = Issue(
        provider="github",
        key="45",
        url="https://github.com/acme/wallet-kit/issues/45",
        title="Fix callback verification",
        project_key="WK",
    )
    cfg = make_cfg(tmp_path)
    provider = FakeProvider(issue)
    monkeypatch.setattr(cli, "load_projects", lambda: {"wallet-kit": cfg})
    monkeypatch.setattr(cli, "get_provider", lambda name: provider)
    monkeypatch.setattr(gitrepo, "all_branch_names", lambda repo: set())
    created = []

    def fake_create_worktree(repo, root, branch, base):
        path = root / branch
        path.mkdir(parents=True)
        created.append((branch, base))
        return path

    monkeypatch.setattr(gitrepo, "create_worktree", fake_create_worktree)
    launches = []
    monkeypatch.setattr(
        agents, "launch", lambda wt, agent, prompt: launches.append(agent) or "t1"
    )
    display_names = []
    monkeypatch.setattr(
        agents, "set_worktree_display_name",
        lambda wt, name, issue_number=None: display_names.append(name),
    )
    return {
        "cfg": cfg,
        "issue": issue,
        "created": created,
        "launches": launches,
        "display_names": display_names,
        "store": lambda: Store(),
    }


def test_start_from_url_creates_worktree_and_task(env, capsys):
    rc = cli.main(["start", env["issue"].url])
    assert rc == 0
    assert env["created"] == [("wk-45", "main")]
    task = env["store"]().get("wallet-kit", "wk-45")
    assert task is not None
    assert task.state == IN_PROGRESS
    assert task.issue.key == "45"
    assert env["launches"] == ["task-analyst"]
    assert "wk-45" in capsys.readouterr().out


def test_start_from_url_sets_orca_display_name_to_issue_key(env):
    rc = cli.main(["start", env["issue"].url])
    assert rc == 0
    assert env["display_names"] == ["45"]


def test_start_unmatched_url_errors(env, capsys):
    rc = cli.main(["start", "https://github.com/other/repo/issues/1"])
    assert rc == 1
    assert "no managed project matches" in capsys.readouterr().err


def test_start_same_issue_reuses(env, capsys):
    assert cli.main(["start", env["issue"].url]) == 0
    assert cli.main(["start", env["issue"].url]) == 0
    assert len(env["created"]) == 1  # no second worktree
    out = capsys.readouterr().out
    assert "already" in out


def test_start_conflicting_branch_gets_suffix(env, monkeypatch):
    monkeypatch.setattr(gitrepo, "all_branch_names", lambda repo: {"wk-45"})
    assert cli.main(["start", env["issue"].url]) == 0
    assert env["created"] == [("wk-45-2", "main")]


def test_start_prompt_requires_project(env, capsys):
    rc = cli.main(["start", "fix callback verification"])
    assert rc == 1
    assert "--project" in capsys.readouterr().err


def test_start_prompt_creates_slug_branch(env, capsys):
    rc = cli.main(
        ["start", "--project", "wallet-kit", "fix callback verification quickly now"]
    )
    assert rc == 0
    assert env["created"] == [("wk-fix-callback-verification-quickly", "main")]
    task = env["store"]().get("wallet-kit", "wk-fix-callback-verification-quickly")
    assert task.issue is None
    assert task.prompt == "fix callback verification quickly now"
    assert task.summary == "fix callback verification quickly now"
    assert env["launches"] == ["task-analyst"]


def test_start_unknown_project_errors(env, capsys):
    rc = cli.main(["start", "--project", "nope", "do something"])
    assert rc == 1
    assert "wallet-kit" in capsys.readouterr().err  # lists configured projects


def make_jira_cfg(tmp_path: Path, name: str = "sezane-oms") -> ProjectConfig:
    return ProjectConfig(
        name=name,
        type="work",
        repo_path=tmp_path / "oms-repo",
        primary_branch="main",
        worktrees_root=tmp_path / "oms-wt",
        provider="jira",
        identity="bleduc",
        project_key="OMS",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
        ci_ignore_checks=[],
    )


@pytest.fixture
def jira_env(env, tmp_path, monkeypatch):
    jira_cfg = make_jira_cfg(tmp_path)
    jira_issue = Issue(
        provider="jira",
        key="OMS-6393",
        url="https://example.atlassian.net/browse/OMS-6393",
        title="Release gallery ML",
        project_key="OMS",
    )
    jira_provider = FakeProvider(jira_issue)
    projects = {"wallet-kit": env["cfg"], "sezane-oms": jira_cfg}
    monkeypatch.setattr(cli, "load_projects", lambda: projects)

    def get_provider(name):
        return jira_provider if name == "jira" else FakeProvider(env["issue"])

    monkeypatch.setattr(cli, "get_provider", get_provider)
    env["jira_cfg"] = jira_cfg
    env["jira_issue"] = jira_issue
    return env


def test_start_prompt_with_issue_key_resolves_issue(jira_env, capsys):
    rc = cli.main(
        ["start", "--project", "sezane-oms", "fix the thing per OMS-6393 please"]
    )
    assert rc == 0
    assert jira_env["created"] == [("oms-6393", "main")]
    task = jira_env["store"]().get("sezane-oms", "oms-6393")
    assert task is not None
    assert task.issue.key == "OMS-6393"
    out = capsys.readouterr().out
    assert "OMS-6393" in out


def test_start_with_jira_key_sets_orca_display_name(jira_env):
    rc = cli.main(
        ["start", "--project", "sezane-oms", "fix the thing per OMS-6393 please"]
    )
    assert rc == 0
    assert jira_env["display_names"] == ["OMS-6393"]


def test_start_prompt_with_unconfigured_key_falls_back_to_slug(jira_env, capsys):
    rc = cli.main(
        ["start", "--project", "wallet-kit", "fix the thing per XYZ-999 please"]
    )
    assert rc == 0
    assert jira_env["created"] == [("wk-fix-the-thing-per", "main")]


def test_start_prompt_without_issue_key_uses_slug(jira_env, capsys):
    rc = cli.main(
        ["start", "--project", "wallet-kit", "fix callback verification quickly now"]
    )
    assert rc == 0
    assert jira_env["created"] == [("wk-fix-callback-verification-quickly", "main")]


def make_shared_key_cfg(tmp_path: Path, name: str, repo_name: str) -> ProjectConfig:
    return ProjectConfig(
        name=name,
        type="work",
        repo_path=tmp_path / repo_name,
        primary_branch="main",
        worktrees_root=tmp_path / f"{name}-wt",
        provider="jira",
        identity="bleduc",
        project_key="OMS",
        sync_strategy="rebase",
        sync_auto_apply=False,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
        ci_ignore_checks=[],
    )


@pytest.fixture
def shared_key_env(env, tmp_path, monkeypatch):
    oms_cfg = make_shared_key_cfg(tmp_path, "sezane-oms", "ecommerce")
    retail_cfg = make_shared_key_cfg(tmp_path, "sezane-retail", "retail")
    jira_issue = Issue(
        provider="jira",
        key="OMS-6393",
        url="https://example.atlassian.net/browse/OMS-6393",
        title="Release gallery ML",
        project_key="OMS",
    )
    jira_provider = FakeProvider(jira_issue)
    projects = {"sezane-oms": oms_cfg, "sezane-retail": retail_cfg}
    monkeypatch.setattr(cli, "load_projects", lambda: projects)
    monkeypatch.setattr(cli, "get_provider", lambda name: jira_provider)
    env["oms_cfg"] = oms_cfg
    env["retail_cfg"] = retail_cfg
    env["jira_issue"] = jira_issue
    return env


def test_start_with_project_disambiguates_shared_key(shared_key_env, capsys):
    rc = cli.main(
        ["start", "--project", "sezane-retail", "OMS-6393"]
    )
    assert rc == 0
    task = shared_key_env["store"]().get("sezane-retail", "oms-6393")
    assert task is not None
    assert task.project == "sezane-retail"
    assert task.issue.key == "OMS-6393"
    out = capsys.readouterr().out
    assert "sezane-retail" in out


def test_start_without_project_uses_first_match_for_shared_key(shared_key_env, capsys):
    rc = cli.main(
        ["start", "OMS-6393"]
    )
    assert rc == 0
    task = shared_key_env["store"]().get("sezane-oms", "oms-6393")
    assert task is not None
    assert task.project == "sezane-oms"
    out = capsys.readouterr().out
    assert "sezane-oms" in out
