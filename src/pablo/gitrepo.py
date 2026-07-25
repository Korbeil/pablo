"""Git plumbing: worktrees, branches, and the lease-safe sync sequence.

Sync rules (spec, "Worktree sync"): always fetch and integrate the branch's
own remote first, then rebase/merge onto the primary branch; push rewritten
history with ``--force-with-lease`` only; on a lease failure roll back and
let the next cycle retry; never resolve conflicts — abort and report them.
"""

from __future__ import annotations

import subprocess
from dataclasses import dataclass, field
from pathlib import Path

from pablo import PabloError


def git(cwd: Path, *args: str, check: bool = True) -> str:
    proc = subprocess.run(
        ["git", "-C", str(cwd), *args], capture_output=True, text=True
    )
    if check and proc.returncode != 0:
        raise PabloError(
            f"git {' '.join(args)} failed in {cwd}: {proc.stderr.strip() or proc.stdout.strip()}"
        )
    return proc.stdout.strip()


def list_worktrees(repo: Path) -> list[tuple[Path, str]]:
    """(path, branch) for every worktree of ``repo``, including repo itself."""
    entries: list[tuple[Path, str]] = []
    current: Path | None = None
    for line in git(repo, "worktree", "list", "--porcelain").splitlines():
        if line.startswith("worktree "):
            current = Path(line.split(" ", 1)[1])
        elif line.startswith("branch ") and current is not None:
            branch = line.split(" ", 1)[1].removeprefix("refs/heads/")
            entries.append((current, branch))
            current = None
    return entries


def all_branch_names(repo: Path) -> set[str]:
    names: set[str] = set()
    out = git(repo, "for-each-ref", "--format=%(refname:short)", "refs/heads", "refs/remotes")
    for ref in out.splitlines():
        ref = ref.strip()
        if not ref or ref.endswith("/HEAD"):
            continue
        if ref.startswith("origin/"):
            ref = ref.removeprefix("origin/")
        names.add(ref)
    return names


def origin_url(repo: Path) -> str | None:
    try:
        return git(repo, "remote", "get-url", "origin")
    except PabloError:
        return None


def create_worktree(repo: Path, worktrees_root: Path, branch: str, base: str) -> Path:
    """New worktree at ``worktrees_root/branch``, branched off ``base``."""
    worktrees_root.mkdir(parents=True, exist_ok=True)
    path = worktrees_root / branch
    if path.exists():
        raise PabloError(f"worktree path already exists: {path}")
    git(repo, "fetch", "origin", check=False)  # best effort; base may be local-only
    start = base
    if _ref_exists(repo, f"origin/{base}"):
        start = f"origin/{base}"
    git(repo, "worktree", "add", "-b", branch, str(path), start)
    return path


def remove_worktree(repo: Path, path: Path, branch: str) -> None:
    git(repo, "worktree", "remove", str(path))
    git(repo, "branch", "-D", branch)


def _ref_exists(cwd: Path, ref: str) -> bool:
    return (
        subprocess.run(
            ["git", "-C", str(cwd), "rev-parse", "--verify", "--quiet", ref],
            capture_output=True,
        ).returncode
        == 0
    )


def push_with_lease(worktree: Path, branch: str) -> None:
    """Module-level so sync_worktree's push seam is patchable in tests."""
    git(worktree, "push", "--force-with-lease", "origin", branch)


@dataclass
class SyncReport:
    worktree: Path
    branch: str
    action: str  # up-to-date | would-sync | synced | conflict | lease-failed | dirty
    behind: int = 0
    ahead: int = 0
    conflict_files: list[str] = field(default_factory=list)
    detail: str = ""


def _counts(wt: Path, upstream: str) -> tuple[int, int]:
    out = git(wt, "rev-list", "--left-right", "--count", f"{upstream}...HEAD")
    left, right = out.split()
    return int(left), int(right)


def _integrate(wt: Path, upstream: str, strategy: str) -> SyncReport | None:
    """Rebase/merge ``upstream`` into the worktree; report on conflict."""
    op = ["rebase", upstream] if strategy == "rebase" else ["merge", "--no-edit", upstream]
    proc = subprocess.run(
        ["git", "-C", str(wt), *op], capture_output=True, text=True
    )
    if proc.returncode == 0:
        return None
    conflict_files = git(wt, "diff", "--name-only", "--diff-filter=U", check=False)
    hunks = git(wt, "diff", "--diff-filter=U", check=False)
    abort = "rebase" if strategy == "rebase" else "merge"
    git(wt, abort, "--abort", check=False)
    return SyncReport(
        worktree=wt,
        branch="",
        action="conflict",
        conflict_files=conflict_files.splitlines(),
        detail="\n".join(hunks.splitlines()[:40])
        or proc.stderr.strip()
        or proc.stdout.strip(),
    )


def sync_worktree(
    wt: Path, branch: str, primary: str, strategy: str, *, apply: bool
) -> SyncReport:
    git(wt, "fetch", "--prune", "origin")

    remote_branch = f"origin/{branch}"
    has_remote = _ref_exists(wt, remote_branch)
    remote_new = _counts(wt, remote_branch)[0] if has_remote else 0

    target = f"origin/{primary}" if _ref_exists(wt, f"origin/{primary}") else primary
    behind, ahead = _counts(wt, target)

    if behind == 0 and remote_new == 0:
        return SyncReport(wt, branch, "up-to-date", behind=behind, ahead=ahead)

    if not apply:
        detail = f"{strategy} onto {target}"
        if remote_new:
            detail = f"integrate {remote_new} commit(s) from {remote_branch}, then {detail}"
        return SyncReport(wt, branch, "would-sync", behind=behind, ahead=ahead, detail=detail)

    if git(wt, "status", "--porcelain"):
        return SyncReport(
            wt, branch, "dirty", behind=behind, ahead=ahead,
            detail="uncommitted changes in worktree; sync skipped",
        )

    orig_head = git(wt, "rev-parse", "HEAD")

    # 1. Integrate the branch's own remote first, so a collaborator's push
    #    is never clobbered and the lease check won't trip on their commits.
    if remote_new:
        conflict = _integrate(wt, remote_branch, strategy)
        if conflict:
            conflict.branch = branch
            return conflict

    # 2. Rebase/merge onto the primary branch.
    conflict = _integrate(wt, target, strategy)
    if conflict:
        git(wt, "reset", "--hard", orig_head, check=False)
        conflict.branch = branch
        return conflict

    # 3. Push (lease-protected) if the branch exists remotely and moved.
    if has_remote and git(wt, "rev-parse", "HEAD") != git(wt, "rev-parse", remote_branch):
        try:
            push_with_lease(wt, branch)
        except PabloError as exc:
            git(wt, "reset", "--hard", orig_head)
            return SyncReport(
                wt, branch, "lease-failed", behind=behind, ahead=ahead,
                detail=f"remote moved during sync; rolled back, will retry next cycle ({exc})",
            )

    return SyncReport(wt, branch, "synced", behind=behind, ahead=ahead)
