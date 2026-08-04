from pathlib import Path

from pablo import gitrepo
from tests.conftest import RepoPair, commit_file, git


def make_worktree(repos: RepoPair, tmp_path: Path, branch: str = "wk-1") -> Path:
    root = tmp_path / "worktrees"
    return gitrepo.create_worktree(repos.clone, root, branch, "main")


def test_create_worktree_branches_off_primary(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    assert wt.is_dir()
    assert git(wt, "branch", "--show-current") == "wk-1"
    assert git(wt, "rev-parse", "HEAD") == git(repos.clone, "rev-parse", "main")


def test_list_worktrees(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    entries = gitrepo.list_worktrees(repos.clone)
    assert (wt.resolve(), "wk-1") in [(p.resolve(), b) for p, b in entries]


def test_all_branch_names_includes_remote(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    git(wt, "push", "-q", "-u", "origin", "wk-1")
    names = gitrepo.all_branch_names(repos.clone)
    assert "wk-1" in names
    assert "main" in names


def test_sync_dry_run_reports_behind(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    commit_file(repos.other, "new.txt", "x\n", "advance main")
    git(repos.other, "push", "-q", "origin", "main")
    head_before = git(wt, "rev-parse", "HEAD")
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=False)
    assert report.action == "would-sync"
    assert report.behind == 1
    assert git(wt, "rev-parse", "HEAD") == head_before  # untouched


def test_sync_up_to_date(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=False)
    assert report.action == "up-to-date"


def test_sync_apply_rebases_and_pushes_with_lease(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    commit_file(wt, "feature.txt", "f\n", "feature work")
    git(wt, "push", "-q", "-u", "origin", "wk-1")
    commit_file(repos.other, "new.txt", "x\n", "advance main")
    git(repos.other, "push", "-q", "origin", "main")
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=True)
    assert report.action == "synced"
    # local branch now contains main's commit, and origin/wk-1 was updated
    assert (wt / "new.txt").exists()
    git(repos.other, "fetch", "-q", "origin")
    assert git(repos.other, "rev-parse", "origin/wk-1") == git(wt, "rev-parse", "HEAD")


def test_sync_integrates_remote_branch_first(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    commit_file(wt, "feature.txt", "f\n", "feature work")
    git(wt, "push", "-q", "-u", "origin", "wk-1")
    # a collaborator pushes to the same branch
    git(repos.other, "fetch", "-q", "origin")
    git(repos.other, "checkout", "-q", "-b", "wk-1", "origin/wk-1")
    commit_file(repos.other, "collab.txt", "c\n", "collaborator commit")
    git(repos.other, "push", "-q", "origin", "wk-1")
    # main also advances
    git(repos.other, "checkout", "-q", "main")
    commit_file(repos.other, "new.txt", "x\n", "advance main")
    git(repos.other, "push", "-q", "origin", "main")
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=True)
    assert report.action == "synced"
    assert (wt / "collab.txt").exists()  # collaborator's work kept
    assert (wt / "new.txt").exists()


def test_sync_conflict_aborts_and_reports_files(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    commit_file(wt, "README.md", "local change\n", "local edit")
    commit_file(repos.other, "README.md", "remote change\n", "remote edit")
    git(repos.other, "push", "-q", "origin", "main")
    head_before = git(wt, "rev-parse", "HEAD")
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=True)
    assert report.action == "conflict"
    assert "README.md" in report.conflict_files
    assert report.detail  # hunk summary present
    assert git(wt, "rev-parse", "HEAD") == head_before  # aborted cleanly
    assert git(wt, "status", "--porcelain") == ""


def test_sync_dirty_worktree_skipped(repos: RepoPair, tmp_path: Path):
    wt = make_worktree(repos, tmp_path)
    (wt / "README.md").write_text("uncommitted\n")
    commit_file(repos.other, "new.txt", "x\n", "advance main")
    git(repos.other, "push", "-q", "origin", "main")
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=True)
    assert report.action == "dirty"


def test_lease_failure_resets_to_orig_head(
    repos: RepoPair, tmp_path: Path, monkeypatch
):
    wt = make_worktree(repos, tmp_path)
    commit_file(wt, "feature.txt", "f\n", "feature work")
    git(wt, "push", "-q", "-u", "origin", "wk-1")
    commit_file(repos.other, "new.txt", "x\n", "advance main")
    git(repos.other, "push", "-q", "origin", "main")
    orig_head = git(wt, "rev-parse", "HEAD")

    real_push = gitrepo.push_with_lease

    def racing_push(worktree, branch):
        # someone pushes to the branch between our fetch and our push
        git(repos.other, "fetch", "-q", "origin")
        git(repos.other, "checkout", "-q", "-b", "wk-1", "origin/wk-1")
        commit_file(repos.other, "race.txt", "r\n", "racing commit")
        git(repos.other, "push", "-q", "origin", "wk-1")
        return real_push(worktree, branch)

    monkeypatch.setattr(gitrepo, "push_with_lease", racing_push)
    report = gitrepo.sync_worktree(wt, "wk-1", "main", "rebase", apply=True)
    assert report.action == "lease-failed"
    assert git(wt, "rev-parse", "HEAD") == orig_head  # rolled back
