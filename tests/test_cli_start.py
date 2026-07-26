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
    return {
        "cfg": cfg,
        "issue": issue,
        "created": created,
        "launches": launches,
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
