"""Per-project worktree sync (spec, "Worktree sync").

Keeps every task worktree up to date with the project's primary branch —
regardless of PABLO state, including post-draft worktrees with open PRs.
Dry-run by default: changes are only applied with ``sync.auto_apply: true``
or an explicit ``--apply``. The cron-triggered run respects ``auto_apply``
from the config; it never implies apply on its own.
"""

from __future__ import annotations

import json
import os
from pathlib import Path

from pablo import PabloError, gitrepo
from pablo.config import ProjectConfig
from pablo.gitrepo import SyncReport
from pablo.model import utcnow
from pablo.store import Store, task_lock

# A locked task is skipped quickly and retried on the next cycle rather
# than stalling the whole sync run behind the state poller.
SYNC_LOCK_TIMEOUT_S = 2

ACTION_ICONS = {
    "up-to-date": "✅",
    "would-sync": "🔄",
    "synced": "✅",
    "conflict": "⚠️",
    "lease-failed": "🔁",
    "dirty": "✋",
    "locked": "🔒",
    "unregistered": "❓",
}


def _discover(cfg: ProjectConfig) -> list[tuple[Path, str]]:
    """Task worktrees: git worktree list minus the primary checkout."""
    return [
        (path, branch)
        for path, branch in gitrepo.list_worktrees(cfg.repo_path)
        if path.resolve() != cfg.repo_path.resolve()
        and branch != cfg.primary_branch
    ]


def sync_project(
    cfg: ProjectConfig, store: Store, *, apply: bool | None
) -> list[SyncReport]:
    effective_apply = cfg.sync_auto_apply if apply is None else apply
    reports: list[SyncReport] = []
    for path, branch in _discover(cfg):
        if not branch:
            reports.append(
                SyncReport(
                    worktree=path,
                    branch="?",
                    action="unregistered",
                    detail="directory under worktrees_root is not a worktree of the repo",
                )
            )
            continue
        task = store.get(cfg.name, branch)
        if task is not None:
            try:
                with task_lock(store, cfg.name, branch, timeout_s=SYNC_LOCK_TIMEOUT_S):
                    reports.append(
                        gitrepo.sync_worktree(
                            path, branch, cfg.primary_branch, cfg.sync_strategy,
                            apply=effective_apply,
                        )
                    )
            except PabloError as exc:
                if "locked" in str(exc):
                    reports.append(
                        SyncReport(
                            worktree=path, branch=branch, action="locked",
                            detail="task busy (state poller or a command holds it); will retry next cycle",
                        )
                    )
                else:
                    raise
        else:
            reports.append(
                gitrepo.sync_worktree(
                    path, branch, cfg.primary_branch, cfg.sync_strategy,
                    apply=effective_apply,
                )
            )
    _save_last_log(cfg.name, cfg.sync_strategy, reports)
    return reports


def logs_dir() -> Path:
    override = os.environ.get("PABLO_LOGS_DIR")
    if override:
        return Path(override)
    return Path("~/.pablo/logs").expanduser()


def _save_last_log(project_name: str, strategy: str, reports: list[SyncReport]) -> None:
    path = logs_dir() / f"rebase-last-{project_name}.json"
    path.parent.mkdir(parents=True, exist_ok=True)
    payload = {
        "timestamp": utcnow(),
        "project": project_name,
        "strategy": strategy,
        "reports": [
            {
                "branch": r.branch,
                "action": r.action,
                "behind": r.behind,
                "ahead": r.ahead,
                "conflict_files": r.conflict_files,
                "detail": r.detail,
            }
            for r in reports
        ],
    }
    path.write_text(json.dumps(payload, indent=2) + "\n")


def load_last_log(project_name: str) -> dict | None:
    path = logs_dir() / f"rebase-last-{project_name}.json"
    if not path.exists():
        return None
    return json.loads(path.read_text())


def render_reports(reports: list[SyncReport]) -> str:
    if not reports:
        return "no task worktrees found"
    lines = []
    for report in reports:
        icon = ACTION_ICONS.get(report.action, "•")
        line = f"{icon} {report.branch:<24} {report.action}"
        if report.action in {"would-sync", "synced"} and (report.behind or report.ahead):
            line += f" (behind {report.behind}, ahead {report.ahead})"
        if report.detail and report.action not in {"conflict"}:
            line += f" — {report.detail}"
        lines.append(line)
        if report.action == "conflict":
            lines.append(f"   conflicting files: {', '.join(report.conflict_files)}")
            for hunk_line in report.detail.splitlines()[:20]:
                lines.append(f"   {hunk_line}")
            lines.append("   left as-is — resolve manually, PABLO never auto-resolves")
    return "\n".join(lines)
