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
import time
from pathlib import Path

from pablo import PabloError, agents, gitrepo
from pablo.config import ProjectConfig
from pablo.gitrepo import SyncReport
from pablo.model import utcnow
from pablo.providers import run_cli
from pablo.store import Store, task_lock

# A locked task is skipped quickly and retried on the next cycle rather
# than stalling the whole sync run behind the state poller.
SYNC_LOCK_TIMEOUT_S = 2

ACTION_ICONS = {
    "up-to-date": "\U0001f49a",
    "would-sync": "🔄",
    "synced": "\U0001f49a",
    "conflict": "\U0001f6ab",
    "lease-failed": "🔁",
    "dirty": "\U0001f4dd",
    "locked": "\U0001f510",
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
    for report in reports:
        if report.action == "conflict" and effective_apply:
            prompt = _build_conflict_agent_prompt(report, cfg)
            agents._launch_headless(report.worktree, "rebase-conflict-resolver", prompt)
            time.sleep(1)
            session_id = _find_recent_session(report.worktree, "rebase-conflict-resolver")
            report.agent_handle = session_id or "launched"
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
                "agent_handle": r.agent_handle,
            }
            for r in reports
        ],
    }
    path.write_text(json.dumps(payload, indent=2) + "\n")


def _build_conflict_agent_prompt(report: SyncReport, cfg: ProjectConfig) -> str:
    target = f"origin/{cfg.primary_branch}"
    lines = [
        f"PABLO sync hit rebase conflicts on branch `{report.branch}`.",
        f"Rebase onto `{target}` using strategy `{cfg.sync_strategy}` was aborted.",
        f"The worktree is clean — you must re-run the rebase yourself.",
        "",
        "1. `git fetch --prune origin`",
        f"2. If `origin/{report.branch}` has new commits, rebase onto it first.",
        f"3. `git rebase {target}`",
        "4. Resolve every conflict. `git add` resolved files, `git rebase --continue`.",
        "5. Repeat until the rebase completes cleanly.",
        f"6. `git push --force-with-lease origin {report.branch}`",
        "",
        "Conflicting files from the original attempt:",
    ]
    for f in report.conflict_files:
        lines.append(f"  - {f}")
    lines.append("")
    return "\n".join(lines)


def _find_recent_session(worktree: Path, agent: str) -> str | None:
    try:
        result = run_cli(
            [
                "opencode", "db",
                f"SELECT id FROM session WHERE directory = '{worktree}'"
                f" AND agent = '{agent}'"
                " ORDER BY time_created DESC LIMIT 1",
                "--format", "json",
            ],
            check=False, timeout=5,
        )
        rows = json.loads(result)
        if rows and isinstance(rows, list) and len(rows) > 0:
            return rows[0].get("id")
    except Exception:
        pass
    return None


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
        if report.action == "conflict" and report.agent_handle:
            line += f" (agent: {report.agent_handle})"
        if report.detail and report.action not in {"conflict"}:
            line += f" — {report.detail}"
        lines.append(line)
        if report.action == "conflict":
            lines.append(f"   conflicting files: {', '.join(report.conflict_files)}")
            if report.agent_handle:
                lines.append("   fix agent running — attach with opencode -s <session> to inspect")
    return "\n".join(lines)
