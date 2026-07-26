from pathlib import Path

import pytest

from pablo import gitrepo, sync
from pablo.config import ProjectConfig
from pablo.model import DRAFT, Task
from pablo.store import Store, task_lock
from tests.conftest import RepoPair, commit_file, git


def make_cfg(repos: RepoPair, tmp_path: Path, auto_apply: bool = False) -> ProjectConfig:
    return ProjectConfig(
        name="proj",
        type="personal",
        repo_path=repos.clone,
        primary_branch="main",
        worktrees_root=tmp_path / "wtroot",
        provider="github",
        identity="user",
        project_key="PR",
        sync_strategy="rebase",
        sync_auto_apply=auto_apply,
        sync_interval=30,
        poll_interval=10,
        failure_signal=None,
        bot_whitelist=[],
    )


@pytest.fixture
def env(repos: RepoPair, tmp_path: Path):
    cfg = make_cfg(repos, tmp_path)
    store = Store(root=tmp_path / "state")
    wt = gitrepo.create_worktree(repos.clone, cfg.worktrees_root, "pr-1", "main")
    store.save(
        Task(project="proj", branch="pr-1", worktree_path=wt, state=DRAFT)
    )
    # advance origin/main so the worktree is behind
    commit_file(repos.other, "new.txt", "x\n", "advance main")
    git(repos.other, "push", "-q", "origin", "main")
    return {"cfg": cfg, "store": store, "wt": wt, "repos": repos}


def test_sync_respects_auto_apply_false_default(env):
    reports = sync.sync_project(env["cfg"], env["store"], apply=None)
    by_branch = {r.branch: r for r in reports}
    assert by_branch["pr-1"].action == "would-sync"
    assert not (env["wt"] / "new.txt").exists()


def test_apply_flag_overrides(env):
    reports = sync.sync_project(env["cfg"], env["store"], apply=True)
    by_branch = {r.branch: r for r in reports}
    assert by_branch["pr-1"].action == "synced"
    assert (env["wt"] / "new.txt").exists()


def test_auto_apply_config_applies(env):
    cfg = make_cfg(env["repos"], env["wt"].parent.parent, auto_apply=True)
    cfg = ProjectConfig(**{**cfg.__dict__, "worktrees_root": env["cfg"].worktrees_root})
    reports = sync.sync_project(cfg, env["store"], apply=None)
    assert {r.branch: r.action for r in reports}["pr-1"] == "synced"


def test_primary_checkout_not_synced(env):
    reports = sync.sync_project(env["cfg"], env["store"], apply=True)
    assert "main" not in [r.branch for r in reports]


def test_locked_task_skipped_cleanly(env):
    with task_lock(env["store"], "proj", "pr-1"):
        reports = sync.sync_project(env["cfg"], env["store"], apply=True)
    by_branch = {r.branch: r for r in reports}
    assert by_branch["pr-1"].action == "locked"
    assert not (env["wt"] / "new.txt").exists()


def test_untracked_worktree_still_synced(env, repos: RepoPair):
    wt2 = gitrepo.create_worktree(repos.clone, env["cfg"].worktrees_root, "pr-x", "main")
    commit_file(repos.other, "new2.txt", "y\n", "advance main again")
    git(repos.other, "push", "-q", "origin", "main")
    reports = sync.sync_project(env["cfg"], env["store"], apply=True)
    by_branch = {r.branch: r for r in reports}
    assert by_branch["pr-x"].action == "synced"
    assert (wt2 / "new2.txt").exists()


def test_reports_render_conflicts(env):
    commit_file(env["wt"], "README.md", "local\n", "local edit")
    commit_file(env["repos"].other, "README.md", "remote\n", "remote edit")
    git(env["repos"].other, "push", "-q", "origin", "main")
    reports = sync.sync_project(env["cfg"], env["store"], apply=True)
    text = sync.render_reports(reports)
    assert "conflict" in text
    assert "README.md" in text
