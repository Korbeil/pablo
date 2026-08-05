from pathlib import Path

import pytest

from pablo import PabloError, agents, ghpr, gitrepo, listing
from pablo.agents import SessionInfo
from pablo.config import ProjectConfig
from pablo.ghpr import PrInfo
from pablo.model import (
    CI_RED,
    DRAFT,
    IN_PROGRESS,
    NEEDS_TESTING,
    READY_TO_REVIEW,
    TESTING_FAILED,
    WAITING_REVIEW,
    Issue,
    Task,
)
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
    monkeypatch.setattr(ghpr, "prs_for_branches", lambda slug, branches: {})
    monkeypatch.setattr(agents, "active_sessions", lambda wt: [])
    monkeypatch.setattr(agents, "bulk_active_sessions", lambda worktrees: {})
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


def test_queue_tasks_empty_store_returns_empty_list(env):
    rows = listing.queue_tasks({"wallet-kit": env["cfg"]}, env["store"], NEEDS_TESTING)
    assert rows == []


def test_queue_tasks_unknown_state_raises(env):
    with pytest.raises(PabloError):
        listing.queue_tasks({"wallet-kit": env["cfg"]}, env["store"], "bogus-state")


def test_queue_tasks_includes_matching_task_with_pr(env, monkeypatch):
    monkeypatch.setattr(
        ghpr, "pr_for_branch",
        lambda slug, branch: PrInfo(
            number=7, title="Fix callbacks", state="OPEN", is_draft=False,
            url="https://github.com/acme/wallet-kit/pull/7", merged_at=None,
        ),
    )
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
    # A task in a different state must not be picked up.
    other = Task(
        project="wallet-kit",
        branch="wk-46",
        worktree_path=Path("/tmp/y"),
        state=WAITING_REVIEW,
    )
    env["store"].save(other)

    rows = listing.queue_tasks({"wallet-kit": env["cfg"]}, env["store"], NEEDS_TESTING)

    assert len(rows) == 1
    row = rows[0]
    assert row["project"] == "wallet-kit"
    assert row["branch"] == "wk-45"
    assert row["issue"]["key"] == "45"
    assert row["pr"] == {
        "number": 7,
        "title": "Fix callbacks",
        "url": "https://github.com/acme/wallet-kit/pull/7",
        "is_draft": False,
    }


def test_queue_tasks_task_with_no_pr_yet(env, monkeypatch):
    monkeypatch.setattr(ghpr, "pr_for_branch", lambda slug, branch: None)
    task = Task(
        project="wallet-kit",
        branch="wk-fix-hooks",
        worktree_path=Path("/tmp/y"),
        state=NEEDS_TESTING,
        summary="fix flaky webhooks",
    )
    env["store"].save(task)

    rows = listing.queue_tasks({"wallet-kit": env["cfg"]}, env["store"], NEEDS_TESTING)

    assert len(rows) == 1
    assert rows[0]["pr"] is None
    assert rows[0]["issue"] is None
    assert rows[0]["summary"] == "fix flaky webhooks"


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
        ghpr, "prs_for_branches",
        lambda slug, branches: {
            b: PrInfo(
                number=7, title="PR", state="OPEN", is_draft=True, url="u", merged_at=None
            )
            for b in branches
        },
    )
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=IN_PROGRESS, pr_number=7)
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "📝 draft" in table


def test_agent_columns(env, monkeypatch):
    monkeypatch.setattr(
        agents, "bulk_active_sessions",
        lambda worktrees: {
            wt: [
                SessionInfo(handle="a", status="running"),
                SessionInfo(handle="b", status="waiting"),
            ]
            for wt in worktrees
        },
    )
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=IN_PROGRESS)
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "🏃 1 · 💭 1" in table


def test_cached_task_renders_without_live_calls(env, monkeypatch):
    """A polled task (cached_* fields set) must not trigger any live
    provider/gh/orca calls — that's the whole point of the cache."""

    def boom(*a, **k):
        raise AssertionError("must not be called when cache is populated")

    monkeypatch.setattr(listing, "get_provider", lambda name: type(
        "P", (), {"issue_status": boom}
    )())
    monkeypatch.setattr(ghpr, "pr_for_branch", boom)
    monkeypatch.setattr(ghpr, "prs_for_branches", boom)
    monkeypatch.setattr(agents, "active_sessions", boom)
    monkeypatch.setattr(agents, "bulk_active_sessions", boom)

    env["store"].save(
        Task(
            project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
            state=NEEDS_TESTING, pr_number=7,
            issue=Issue(provider="github", key="45", url="u", title="Fix callbacks",
                        project_key="WK"),
            cached_tracker_status="In Review",
            cached_pr_state="📖 open #7",
            cached_agent_count=2,
            cached_agent_activity="🏃 2",
        )
    )
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "In Review" in table
    assert "📖 open #7" in table
    assert "🏃 2" in table


def test_live_flag_bypasses_cache_without_writing_it(env):
    task = Task(
        project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
        state=NEEDS_TESTING,
        issue=Issue(provider="github", key="45", url="u", title="Fix callbacks",
                    project_key="WK"),
        cached_tracker_status="stale status",
    )
    env["store"].save(task)
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"], live=True)
    assert "In Progress" in table  # live value from FakeProvider, not the cache
    assert "stale status" not in table
    reloaded = env["store"].get("wallet-kit", "wk-45")
    assert reloaded.cached_tracker_status == "stale status"  # untouched


def test_refresh_flag_fetches_live_and_persists(env):
    task = Task(
        project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
        state=NEEDS_TESTING,
        issue=Issue(provider="github", key="45", url="u", title="Fix callbacks",
                    project_key="WK"),
        cached_tracker_status="stale status",
    )
    env["store"].save(task)
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"], refresh=True)
    assert "In Progress" in table
    reloaded = env["store"].get("wallet-kit", "wk-45")
    assert reloaded.cached_tracker_status == "In Progress"
    assert reloaded.cached_at is not None


def test_tasks_split_waiting_feedback_first(env, monkeypatch):
    monkeypatch.setattr(
        agents, "bulk_active_sessions",
        lambda worktrees: {
            wt: [SessionInfo(handle="a", status="waiting")]
            if str(wt) == "/tmp/x"
            else []
            for wt in worktrees
        },
    )

    env["store"].save(Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
                           state=IN_PROGRESS))
    env["store"].save(Task(project="wallet-kit", branch="wk-46", worktree_path=Path("/tmp/y"),
                           state=NEEDS_TESTING))

    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "💭 Waiting for feedback" in table
    assert "Other tasks" in table
    # wk-45 has the waiting session, so it must be in the first section
    other_idx = table.index("Other tasks")
    assert table.index("wk-45") < other_idx
    assert table.index("wk-46") > other_idx


def test_tasks_sorted_by_state(env):
    # Save in reverse of desired order; output must re-sort them
    env["store"].save(Task(project="wallet-kit", branch="wk-in-progress",
                           worktree_path=Path("/tmp/a"), state=IN_PROGRESS))
    env["store"].save(Task(project="wallet-kit", branch="wk-testing-failed",
                           worktree_path=Path("/tmp/b"), state=TESTING_FAILED))

    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    lines = [l for l in table.splitlines() if "wk-" in l]
    assert "wk-testing-failed" in lines[0]
    assert "wk-in-progress" in lines[1]


def test_tasks_no_waiting_header_when_no_waiting_agents(env):
    env["store"].save(Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
                           state=IN_PROGRESS))
    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "💭 Waiting for feedback" not in table
    assert "Other tasks" not in table  # header omitted for single-section
    assert "wk-45" in table


def test_tasks_waiting_agent_excluded_for_non_eligible_state(env, monkeypatch):
    monkeypatch.setattr(
        agents, "bulk_active_sessions",
        lambda worktrees: {
            wt: [SessionInfo(handle="a", status="waiting")]
            if str(wt) == "/tmp/x"
            else []
            for wt in worktrees
        },
    )
    env["store"].save(Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
                           state=NEEDS_TESTING))

    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    assert "💭 Waiting for feedback" not in table
    assert "Other tasks" not in table  # single section, no header
    assert "wk-45" in table


# ----------------------------------------------------------- render_slack --


def test_render_slack_empty_rows_waiting_review():
    assert listing.render_slack([], WAITING_REVIEW) == "No PRs waiting for review right now 🎉"


def test_render_slack_empty_rows_needs_testing():
    assert listing.render_slack([], NEEDS_TESTING) == "Nothing needs testing right now 🎉"


def test_render_slack_empty_rows_unknown_state_falls_back():
    assert "🎉" in listing.render_slack([], "request-changes")


def _row(project, pr):
    return {"project": project, "branch": "b", "issue": None, "summary": None, "pr": pr}


def test_render_slack_groups_by_project_with_mrkdwn_links():
    rows = [
        _row("wallet-kit", {"number": 7, "title": "Fix callbacks",
                            "url": "https://github.com/acme/wallet-kit/pull/7", "is_draft": False}),
        _row("acme-pim", {"number": 12, "title": "Add exports",
                          "url": "https://github.com/acme/pim/pull/12", "is_draft": False}),
        _row("wallet-kit", {"number": 9, "title": "Tidy tests",
                            "url": "https://github.com/acme/wallet-kit/pull/9", "is_draft": False}),
    ]
    out = listing.render_slack(rows, WAITING_REVIEW)
    assert out == (
        "*wallet-kit*\n"
        "• https://github.com/acme/wallet-kit/pull/7 #7 Fix callbacks\n"
        "• https://github.com/acme/wallet-kit/pull/9 #9 Tidy tests\n"
        "*acme-pim*\n"
        "• https://github.com/acme/pim/pull/12 #12 Add exports"
    )


def test_render_slack_skips_tasks_without_pr_and_drops_empty_groups():
    rows = [
        _row("wallet-kit", None),
        _row("acme-pim", {"number": 12, "title": "Add exports",
                          "url": "https://github.com/acme/pim/pull/12", "is_draft": False}),
    ]
    out = listing.render_slack(rows, NEEDS_TESTING)
    assert out == (
        "*acme-pim*\n"
        "• https://github.com/acme/pim/pull/12 #12 Add exports"
    )


def test_render_slack_uses_issue_key_and_title_when_issue_present():
    rows = [
        {
            "project": "wallet-kit",
            "branch": "wk-45",
            "issue": {"key": "WK-45", "title": "Fix callbacks", "url": "https://acme.atlassian.net/browse/WK-45"},
            "summary": None,
            "pr": {"number": 7, "title": "PR title ignored", "url": "https://github.com/acme/wallet-kit/pull/7", "is_draft": False},
        },
    ]
    out = listing.render_slack(rows, NEEDS_TESTING)
    assert out == (
        "*wallet-kit*\n"
        "• https://github.com/acme/wallet-kit/pull/7 WK-45 Fix callbacks"
    )


def test_render_slack_all_prs_missing_returns_empty_message():
    rows = [_row("wallet-kit", None)]
    assert listing.render_slack(rows, WAITING_REVIEW) == "No PRs waiting for review right now 🎉"


# ------------------------------------------------------- column alignment --


def test_display_width_ascii():
    assert listing._display_width("hello") == 5
    assert listing._display_width("") == 0


def test_display_width_emoji():
    assert listing._display_width("🔨") == 2
    assert listing._display_width("✅") == 2
    assert listing._display_width("💭") == 2


def test_display_width_mixed():
    assert listing._display_width("🔨 in-progress") == 14
    assert listing._display_width("🏃 1 · 💭 1") == 11

def test_pad_right_emoji_cell():
    assert listing._pad_right("hello", 8) == "hello   "
    assert listing._pad_right("🔨 x", 6) == "🔨 x  "  # display width 4, needs 2 spaces
    assert listing._pad_right("✅ merged #7", 15) == "✅ merged #7   "


def test_all_task_rows_aligned(env, monkeypatch):
    monkeypatch.setattr(
        agents, "bulk_active_sessions",
        lambda worktrees: {
            wt: [SessionInfo(handle="a", status="running"), SessionInfo(handle="b", status="waiting")]
            for wt in worktrees
        },
    )
    monkeypatch.setattr(
        ghpr, "prs_for_branches",
        lambda slug, branches: {
            b: PrInfo(number=7, title="PR", state="OPEN", is_draft=True, url="u", merged_at=None)
            for b in branches
        },
    )
    env["store"].save(
        Task(project="wallet-kit", branch="wk-45", worktree_path=Path("/tmp/x"),
             state=IN_PROGRESS, pr_number=7,
             issue=Issue(provider="github", key="45", url="u", title="Fix callbacks", project_key="WK"))
    )
    env["store"].save(
        Task(project="wallet-kit", branch="wk-fix-hooks", worktree_path=Path("/tmp/y"),
             state=NEEDS_TESTING, summary="fix flaky webhooks")
    )

    table = listing.tasks_table({"wallet-kit": env["cfg"]}, env["store"])
    lines = table.splitlines()

    separator = next(l for l in lines if l.strip() and all(c in "- " for c in l))
    expected_width = listing._display_width(separator)

    data_lines = [
        l for l in lines
        if l.strip()
        and not l.startswith("Project")
        and not l.startswith("-")
        and not l.startswith("💭")
        and not l.startswith("Other")
    ]

    for i, line in enumerate(data_lines):
        assert listing._display_width(line) == expected_width, \
            f"Line {i} display width {listing._display_width(line)} != {expected_width}: {line!r}"
