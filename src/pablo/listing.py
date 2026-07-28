"""Terminal tables: the issue-tracking view and the active-task listing.

Both are strictly read-only. Legend (documented in @docs/listings.md):
states 🔨 in-progress · ⏸️ waiting · 📝 draft · 🔴 ci-red ·
👀 waiting-review (also used for the momentary ready-to-review) ·
🧪 needs-testing · 🔁 request-changes · ❌ testing-failed;
PRs 📬 open · 📪 draft · ✅ merged (merged only appears when auto-close is
deferred because agents are still running);
agents 🏃 running · ⏳ waiting on feedback.
"""

from __future__ import annotations

from pablo import PabloError, agents, ghpr, gitrepo
from pablo.agents import SessionInfo
from pablo.config import ProjectConfig
from pablo.ghpr import PrInfo
from pablo.model import ALL_STATES, Task, utcnow
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


# Shared with poller.py, which writes these back to the store on every
# poll cycle so `pablo tasks` can read them instead of fetching live.


def pr_state_cell(task: Task, pr: PrInfo | None) -> str:
    if task.merged:
        return "✅ merged"
    if task.pr_number is None:
        return "-"
    if pr is None:
        return f"#{task.pr_number}"
    if pr.state == "MERGED":
        return f"✅ merged #{pr.number}"
    if pr.is_draft:
        return f"📪 draft #{pr.number}"
    return f"📬 open #{pr.number}"


def agent_activity_summary(sessions: list[SessionInfo]) -> tuple[int, str]:
    if not sessions:
        return 0, "-"
    running = sum(1 for session in sessions if session.status == "running")
    waiting = sum(1 for session in sessions if session.status == "waiting")
    parts = []
    if running:
        parts.append(f"🏃 {running}")
    if waiting:
        parts.append(f"⏳ {waiting}")
    return len(sessions), " · ".join(parts)


def _remote_status_cell(task: Task, cfg: ProjectConfig, *, live: bool) -> str:
    if task.issue is None:
        return "N/A"
    if not live and task.cached_tracker_status is not None:
        return task.cached_tracker_status
    try:
        return get_provider(cfg.provider).issue_status(task.issue.key, cfg)
    except PabloError:
        return "?"


def _pr_cell(
    task: Task, cfg: ProjectConfig, *, live: bool, prefetched: dict[str, PrInfo] | None = None
) -> str:
    if not live and task.cached_pr_state is not None:
        return task.cached_pr_state
    if task.merged:
        return "✅ merged"
    if task.pr_number is None:
        return "-"
    if prefetched is not None:
        return pr_state_cell(task, prefetched.get(task.branch))
    try:
        pr = ghpr.pr_for_branch(_repo_slug(cfg), task.branch)
    except PabloError:
        return f"#{task.pr_number}"
    return pr_state_cell(task, pr)


def _agent_cells(
    task: Task, *, live: bool, sessions: list[SessionInfo] | None = None
) -> tuple[str, str]:
    if not live and task.cached_agent_count is not None:
        return str(task.cached_agent_count), task.cached_agent_activity or "-"
    if sessions is None:
        sessions = agents.active_sessions(task.worktree_path)
    count, activity = agent_activity_summary(sessions)
    return str(count), activity


def tasks_table(
    projects: dict[str, ProjectConfig],
    store: Store,
    *,
    live: bool = False,
    refresh: bool = False,
) -> str:
    """Renders each task's Tracker/PR/Agents columns from the poller's
    cached display fields by default (instant, no live calls) — pass
    ``live=True`` for a one-off live fetch, or ``refresh=True`` to fetch
    live and persist the result as the new cache, same as the poller."""
    fetch_live = live or refresh
    tasks = [t for t in store.all_tasks() if t.project in projects]

    needs_agent_check = [
        t for t in tasks if fetch_live or t.cached_agent_count is None
    ]
    sessions_by_worktree = (
        agents.bulk_active_sessions([t.worktree_path for t in needs_agent_check])
        if needs_agent_check
        else {}
    )

    needs_pr_check = [
        t for t in tasks
        if not t.merged and t.pr_number is not None
        and (fetch_live or t.cached_pr_state is None)
    ]
    branches_by_repo: dict[str, list[str]] = {}
    for task in needs_pr_check:
        slug = _repo_slug(projects[task.project])
        branches_by_repo.setdefault(slug, []).append(task.branch)
    prs_by_repo: dict[str, dict[str, PrInfo]] = {}
    for slug, branches in branches_by_repo.items():
        try:
            prs_by_repo[slug] = ghpr.prs_for_branches(slug, branches)
        except PabloError:
            prs_by_repo[slug] = {}

    rows = []
    for task in tasks:
        cfg = projects[task.project]
        tracker = _remote_status_cell(task, cfg, live=fetch_live)
        slug = _repo_slug(cfg)
        pr = _pr_cell(task, cfg, live=fetch_live, prefetched=prs_by_repo.get(slug))
        count, activity = _agent_cells(
            task, live=fetch_live, sessions=sessions_by_worktree.get(task.worktree_path)
        )
        if refresh:
            if task.issue is not None and tracker != "?":
                task.cached_tracker_status = tracker
            task.cached_pr_state = pr
            task.cached_agent_count = int(count)
            task.cached_agent_activity = activity
            task.cached_at = utcnow()
            store.save(task)
        rows.append(
            [
                f"{task.branch} ({task.project})",
                _state_cell(task),
                count,
                activity,
                _issue_cell(task),
                tracker,
                pr,
            ]
        )
    if not rows:
        return "no active tasks"
    return _render(
        ["Task", "State", "Agents", "Activity", "Issue", "Tracker", "PR"], rows
    )


# --------------------------------------------------------- queue export ---


def queue_tasks(
    projects: dict[str, ProjectConfig], store: Store, state: str
) -> list[dict]:
    """Tasks currently in ``state``, across all projects, each enriched with
    a live-fetched PR (title/url) for review-queue commands like
    /pablo-needs-testing and /pablo-waiting-review to format for Slack."""
    if state not in ALL_STATES:
        raise PabloError(f"unknown state {state!r} (expected one of {list(ALL_STATES)})")
    rows = []
    for task in store.all_tasks():
        if task.state != state:
            continue
        cfg = projects.get(task.project)
        if cfg is None:
            continue
        try:
            info = ghpr.pr_for_branch(_repo_slug(cfg), task.branch)
        except PabloError:
            info = None
        pr = (
            {"number": info.number, "title": info.title, "url": info.url, "is_draft": info.is_draft}
            if info is not None
            else None
        )
        rows.append(
            {
                "project": task.project,
                "branch": task.branch,
                "issue": task.issue.to_json() if task.issue else None,
                "summary": task.summary,
                "pr": pr,
            }
        )
    return rows
