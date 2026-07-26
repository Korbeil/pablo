"""Terminal tables: the issue-tracking view and the active-task listing.

Both are strictly read-only. Legend (documented in @README.md):
states 🔨 in-progress · ⏸️ waiting · 📝 draft · 🔴 ci-red ·
👀 waiting-review (also used for the momentary ready-to-review) ·
🧪 needs-testing · 🔁 request-changes · ❌ testing-failed;
PRs 📬 open · 📪 draft · ✅ merged (merged only appears when auto-close is
deferred because agents are still running);
agents 🏃 running · ⏳ waiting on feedback.
"""

from __future__ import annotations

from pablo import PabloError, agents, ghpr, gitrepo
from pablo.config import ProjectConfig
from pablo.model import Task
from pablo.providers import get_provider
from pablo.states import STATES
from pablo.store import Store


def _repo_slug(cfg: ProjectConfig) -> str:
    from pablo.providers.github import repo_slug

    return repo_slug(cfg)


def _render(headers: list[str], rows: list[list[str]]) -> str:
    widths = [
        max(len(headers[i]), *(len(row[i]) for row in rows)) if rows else len(headers[i])
        for i in range(len(headers))
    ]
    lines = [
        "  ".join(headers[i].ljust(widths[i]) for i in range(len(headers))),
        "  ".join("-" * widths[i] for i in range(len(headers))),
    ]
    for row in rows:
        lines.append("  ".join(row[i].ljust(widths[i]) for i in range(len(headers))))
    return "\n".join(lines)


# -------------------------------------------------------- issues table ----


def _branch_candidates(base: str, names: set[str]) -> list[str]:
    """base plus any -2/-3... suffix variants that actually exist."""
    found = [name for name in names if name == base]
    n = 2
    while f"{base}-{n}" in names:
        found.append(f"{base}-{n}")
        n += 1
    return found


def issues_table(cfg: ProjectConfig, store: Store) -> str:
    provider = get_provider(cfg.provider)
    issues = provider.list_assigned(cfg)
    names = gitrepo.all_branch_names(cfg.repo_path)
    slug = _repo_slug(cfg)

    rows = []
    for issue in issues:
        if cfg.provider == "github":
            base = f"{(cfg.project_key or '').lower()}-{issue.key.lower()}"
        else:
            base = issue.key.lower()
        branches = _branch_candidates(base, names)

        pr_cell = "-"
        for branch in branches:
            pr = ghpr.pr_for_branch(slug, branch)
            if pr is not None:
                state = "merged" if pr.state == "MERGED" else (
                    "draft" if pr.is_draft else pr.state.lower()
                )
                pr_cell = f"#{pr.number} {pr.title} ({state})"
                break

        rows.append(
            [
                issue.key,
                issue.title,
                issue.status or "-",
                pr_cell,
                ", ".join(branches) if branches else "-",
            ]
        )
    return _render(["Issue", "Title", "Status", "PR", "Branch"], rows)


# --------------------------------------------------------- tasks table ----


def _state_cell(task: Task) -> str:
    state_def = STATES.get(task.state)
    if state_def is None:
        return task.state
    return f"{state_def.emoji} {state_def.label}"


def _issue_cell(task: Task) -> str:
    if task.issue is not None:
        return f"{task.issue.key} {task.issue.title}"
    return task.summary or "-"


def _remote_status_cell(task: Task, cfg: ProjectConfig) -> str:
    if task.issue is None:
        return "N/A"
    try:
        return get_provider(cfg.provider).issue_status(task.issue.key, cfg)
    except PabloError:
        return "?"


def _pr_cell(task: Task, cfg: ProjectConfig) -> str:
    if task.merged:
        return "✅ merged"
    if task.pr_number is None:
        return "-"
    try:
        pr = ghpr.pr_for_branch(_repo_slug(cfg), task.branch)
    except PabloError:
        return f"#{task.pr_number}"
    if pr is None:
        return f"#{task.pr_number}"
    if pr.state == "MERGED":
        return f"✅ merged #{pr.number}"
    if pr.is_draft:
        return f"📪 draft #{pr.number}"
    return f"📬 open #{pr.number}"


def _agent_cells(task: Task) -> tuple[str, str]:
    sessions = agents.active_sessions(task.worktree_path)
    if not sessions:
        return "0", "-"
    running = sum(1 for session in sessions if session.status == "running")
    waiting = sum(1 for session in sessions if session.status == "waiting")
    parts = []
    if running:
        parts.append(f"🏃 {running}")
    if waiting:
        parts.append(f"⏳ {waiting}")
    return str(len(sessions)), " · ".join(parts)


def tasks_table(projects: dict[str, ProjectConfig], store: Store) -> str:
    rows = []
    for task in store.all_tasks():
        cfg = projects.get(task.project)
        if cfg is None:
            continue
        count, activity = _agent_cells(task)
        rows.append(
            [
                f"{task.branch} ({task.project})",
                _state_cell(task),
                _issue_cell(task),
                _remote_status_cell(task, cfg),
                _pr_cell(task, cfg),
                count,
                activity,
            ]
        )
    if not rows:
        return "no active tasks"
    return _render(
        ["Task", "State", "Issue", "Tracker", "PR", "Agents", "Activity"], rows
    )
