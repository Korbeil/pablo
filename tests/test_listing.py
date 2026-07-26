from pathlib import Path

import pytest

from pablo import agents, ghpr, gitrepo, listing
from pablo.agents import SessionInfo
from pablo.config import ProjectConfig
from pablo.ghpr import PrInfo
from pablo.model import IN_PROGRESS, NEEDS_TESTING, READY_TO_REVIEW, Issue, Task
from pablo.store import Store


def make_cfg(tmp_path: Path) -> ProjectConfig:
    return ProjectConfig(
        name="wallet-kit",
        type="open-source",
        repo_path=tmp_path / "repo",
        primary_branch="main",
        worktrees_root=tmp_path / "wt",
        provider="github",
        identity="user",
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
    def __init__(self):
        self.issues = [
            Issue(provider="github", key="45", url="u45", title="Fix callbacks",
                  project_key="WK", status="To Do"),
            Issue(provider="github", key="46", url="u46", title="Add exports",
                  project_key="WK", status="Done"),
        ]

    def list_assigned(self, cfg):
        return self.issues

    def issue_status(self, key, cfg):
        return {"45": "In Progress"}.get(key, "To Do")


@pytest.fixture
def env(tmp_path, monkeypatch):
    cfg = make_cfg(tmp_path)
    store = Store(root=tmp_path / "state")
    monkeypatch.setattr(listing, "get_provider", lambda name: FakeProvider())
    monkeypatch.setattr(listing, "_repo_slug", lambda cfg: "acme/wallet-kit")
    monkeypatch.setattr(gitrepo, "all_branch_names", lambda repo: {"wk-45", "main"})
    monkeypatch.setattr(ghpr, "pr_for_branch", lambda slug, branch: None)
    monkeypatch.setattr(agents, "active_sessions", lambda wt: [])
    return {"cfg": cfg, "store": store}


def test_issues_table_shows_branch_and_blank_cells(env, monkeypatch):
    monkeypatch.setattr(
        ghpr, "pr_for_branch",
        lambda slug, branch: PrInfo(
            number=7, title="PR", state="OPEN", is_draft=False, url="u", merged_at=None
        )
        if branch == "wk-45"
        else None,
    )
    table = listing.issues_table(env["cfg"], env["store"])
    lines = table.splitlines()
    row45 = next(line for line in lines if "45" in line and "Fix callbacks" in line)
    assert "wk-45" in row45
    assert "#7" in row45
    row46 = next(line for line in lines if "Add exports" in line)
    assert "-" in row46  # no PR, no branch


def test_tasks_row_for_issue_task(env):
    task = Task(
        project="wallet-kit",
        branch="wk-45",
        worktree_path=Path("/tmp/x"),
        state=NEEDS_TESTING,
        pr_number=7,
        issue=Issue(provider="github", key="45", url="u", title="Fix callbacks",
                    project_key="WK"),
    )
    env["store"].save(task)
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "🧪 needs-testing" in table
    assert "wk-45" in table
    assert "Fix callbacks" in table
    assert "In Progress" in table  # remote status fetched


def test_tasks_row_prompt_task_shows_summary_and_na(env):
    task = Task(
        project="wallet-kit",
        branch="wk-fix-hooks",
        worktree_path=Path("/tmp/y"),
        state=IN_PROGRESS,
        summary="fix flaky webhooks",
    )
    env["store"].save(task)
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "fix flaky webhooks" in table
    assert "N/A" in table


def test_ready_to_review_rendered_as_waiting_review(env):
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=READY_TO_REVIEW)
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "👀 waiting-review" in table
    assert "ready-to-review" not in table


def test_merged_deferred_shows_check_emoji(env, monkeypatch):
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=NEEDS_TESTING, pr_number=7, merged=True)
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "✅ merged" in table


def test_pr_states_rendered(env, monkeypatch):
    monkeypatch.setattr(
        ghpr, "pr_for_branch",
        lambda slug, branch: PrInfo(
            number=7, title="PR", state="OPEN", is_draft=True, url="u", merged_at=None
        ),
    )
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=IN_PROGRESS, pr_number=7)
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "📪 draft" in table


def test_agent_columns(env, monkeypatch):
    monkeypatch.setattr(
        agents, "active_sessions",
        lambda wt: [
            SessionInfo(handle="a", status="running"),
            SessionInfo(handle="b", status="waiting"),
        ],
    )
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=IN_PROGRESS)
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "🏃 1 · ⏳ 1" in table
