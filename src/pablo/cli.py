"""The ``pablo`` command-line entry point.

Interactive OpenCode commands (/pablo-start, /pablo-state, ...) and the
background systemd timer are all thin wrappers over these subcommands; the
deterministic logic lives in the pablo package, never in the markdown.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import sys
from pathlib import Path

from pablo import PabloError, agents, ghpr, gitrepo, naming
from pablo.config import ProjectConfig, load_projects
from pablo.model import CI_RED, IN_PROGRESS, Issue, READY_TO_REVIEW, Task, utcnow
from pablo.providers import get_provider
from pablo.states import (
    COMMIT_ALLOWED_FROM,
    LAUNCH_SPECS,
    TaskCtx,
    _specs_for,
    enter_state,
    toggle_waiting,
)
from pablo.store import Store, task_lock

SUMMARY_MAX_WORDS = 5


def _fail(message: str) -> int:
    print(f"pablo: {message}", file=sys.stderr)
    return 1


# ---------------------------------------------------------------- start ---


def _match_issue_url(url: str, projects: dict[str, ProjectConfig], project: str | None = None):
    candidate_cfgs = [projects[project]] if project is not None and project in projects else projects.values()
    for cfg in candidate_cfgs:
        provider = get_provider(cfg.provider)
        ref = provider.match_url(url, cfg)
        if ref is not None:
            return cfg, provider, ref
    return None


def _branch_base(cfg: ProjectConfig, issue: Issue) -> str:
    if cfg.provider == "github":
        return naming.branch_name(cfg.project_key or "", issue.key)
    # Jira/Linear issue keys are already [project-key]-[issue-id].
    return issue.key.lower()


_ISSUE_KEY_RE = re.compile(r"\b([A-Za-z][A-Za-z0-9]*)-(\d+)\b")


def _match_issue_key(text: str, projects: dict[str, ProjectConfig], project: str | None = None):
    """Find a Jira/Linear issue key (e.g. ``OMS-6393``) mentioned anywhere in
    a free-text prompt, matched against a configured project's
    ``project_key``. GitHub is excluded: its ``project_key`` is just a
    configured branch prefix, not part of how its issues are referenced.

    When *project* is given, only that project is checked. Otherwise every
    configured project is iterated and the first match wins."""
    candidate_cfgs = [projects[project]] if project is not None and project in projects else projects.values()
    for match in _ISSUE_KEY_RE.finditer(text):
        prefix = match.group(1).upper()
        for cfg in candidate_cfgs:
            if cfg.provider not in ("jira", "linear"):
                continue
            if (cfg.project_key or "").upper() == prefix:
                return cfg, get_provider(cfg.provider), match.group(0).upper()
    return None


def _create_task(
    store: Store,
    cfg: ProjectConfig,
    branch: str,
    *,
    issue: Issue | None,
    prompt: str | None,
) -> Task:
    with task_lock(store, cfg.name, branch):
        worktree = gitrepo.create_worktree(
            cfg.repo_path, cfg.worktrees_root, branch, cfg.primary_branch
        )
        task = Task(
            project=cfg.name,
            branch=branch,
            worktree_path=worktree,
            state=IN_PROGRESS,
            issue=issue,
            prompt=prompt,
            summary=" ".join(prompt.split()[:SUMMARY_MAX_WORDS]) if prompt else None,
        )
        ctx = TaskCtx(task=task, cfg=cfg, store=store)
        enter_state(ctx, IN_PROGRESS)  # runs task-analyst (once)
    return task


def _start_issue_task(store: Store, cfg: ProjectConfig, issue: Issue) -> int:
    for existing in store.all_tasks(cfg.name):
        if existing.issue is not None and existing.issue.key == issue.key:
            print(
                f"task for {issue.key} already exists: worktree "
                f"{existing.worktree_path} (state {existing.state}) — reusing it"
            )
            return 0
    base = _branch_base(cfg, issue)
    branch = naming.dedupe(base, gitrepo.all_branch_names(cfg.repo_path))
    task = _create_task(store, cfg, branch, issue=issue, prompt=None)
    # Orca derives the workspace displayName from the branch (lowercase
    # ``oms-6407``) until the analyst's first message self-corrects it; set
    # it up front so the Orca UI shows ``OMS-6407`` immediately. Pass the
    # GitHub issue number when available so Orca links the correct issue
    # instead of auto-detecting a wrong PR from the branch's digits; pass
    # null for non-GitHub trackers so Orca doesn't guess a stale PR.
    # Best-effort: if Orca hasn't indexed the worktree yet (or isn't
    # available) the poller re-tries on its next cycle.
    gh_issue = issue.key if cfg.provider == "github" else None
    agents.set_worktree_display_name(task.worktree_path, issue.key, gh_issue)
    print(
        f"started {issue.key} ({issue.title}) in project {cfg.name}\n"
        f"worktree: {task.worktree_path} (branch {branch})\n"
        f"state: {task.state} — task-analyst is running"
    )
    return 0


def cmd_start(args: argparse.Namespace) -> int:
    projects = load_projects()
    store = Store()
    text = " ".join(args.input).strip()
    if not text:
        return _fail("usage: pablo start <issue-url> | pablo start --project <name> \"<prompt>\"")

    if text.startswith(("http://", "https://")):
        matched = _match_issue_url(text, projects, project=args.project)
        if matched is None:
            return _fail(
                f"no managed project matches this issue URL: {text} — check the "
                f"issue_tracker config of your projects"
            )
        cfg, provider, ref = matched
        issue = provider.get_issue(ref, cfg)
        return _start_issue_task(store, cfg, issue)

    key_matched = _match_issue_key(text, projects, project=args.project)
    if key_matched is not None:
        cfg, provider, key = key_matched
        issue = provider.get_issue(key, cfg)
        return _start_issue_task(store, cfg, issue)

    if not args.project:
        names = ", ".join(sorted(projects)) or "none configured"
        return _fail(
            f"a plain-prompt task needs --project <name>; configured projects: {names}"
        )
    cfg = projects.get(args.project)
    if cfg is None:
        return _fail(
            f"unknown project {args.project!r}; configured projects: "
            + ", ".join(sorted(projects))
        )
    base = naming.slug_branch(cfg.project_key or "", text)
    branch = naming.dedupe(base, gitrepo.all_branch_names(cfg.repo_path))
    task = _create_task(store, cfg, branch, issue=None, prompt=text)
    # Prompt-only tasks have no tracker issue; pass null explicitly so Orca
    # doesn't auto-detect a stale/wrong PR from the branch's digits.
    agents.set_worktree_display_name(task.worktree_path, branch)
    print(
        f"started task in project {cfg.name}\n"
        f"worktree: {task.worktree_path} (branch {branch})\n"
        f"state: {task.state} — task-analyst is running"
    )
    return 0


# ----------------------------------------------------------------- sync ---


def cmd_sync(args: argparse.Namespace) -> int:
    from pablo import sync as sync_mod

    projects = load_projects()
    if args.project:
        if args.project not in projects:
            return _fail(
                f"unknown project {args.project!r}; configured projects: "
                + ", ".join(sorted(projects))
            )
        projects = {args.project: projects[args.project]}
    store = Store()
    apply = True if args.apply else None
    for name, cfg in projects.items():
        reports = sync_mod.sync_project(cfg, store, apply=apply)
        print(f"# {name}")
        print(sync_mod.render_reports(reports))
    return 0


def cmd_rebase_log(args: argparse.Namespace) -> int:
    from pablo import sync as sync_mod

    projects = load_projects()
    if args.project:
        if args.project not in projects:
            return _fail(
                f"unknown project {args.project!r}; configured projects: "
                + ", ".join(sorted(projects))
            )
        names = [args.project]
    else:
        names = sorted(projects)

    found = False
    for name in names:
        log = sync_mod.load_last_log(name)
        if log is None:
            continue
        found = True
        ts = log["timestamp"]
        strategy = log.get("strategy", "unknown")
        print(f"# {name} — {ts} ({strategy})")
        for r in log["reports"]:
            icon = sync_mod.ACTION_ICONS.get(r["action"], "•")
            line = f"{icon} {r['branch']:<24} {r['action']}"
            behind = r.get("behind", 0)
            ahead = r.get("ahead", 0)
            if r["action"] in {"would-sync", "synced"} and (behind or ahead):
                line += f" (behind {behind}, ahead {ahead})"
            detail = r.get("detail", "")
            if detail and r["action"] not in {"conflict"}:
                line += f" — {detail}"
            if r["action"] == "conflict":
                agent_handle = r.get("agent_handle", "")
                if agent_handle:
                    line += f" (agent: {agent_handle})"
            print(line)
            if r["action"] == "conflict":
                agent_handle = r.get("agent_handle", "")
                conflict_files = r.get("conflict_files", [])
                if conflict_files:
                    print(f"   conflicting files: {', '.join(conflict_files)}")
                if agent_handle:
                    print("   fix agent running — attach with opencode -s <session> to inspect")
    if not found:
        print("no rebase logs found — run `pablo sync` first")
    return 0


def cmd_issues(args: argparse.Namespace) -> int:
    from pablo import listing

    projects = load_projects()
    if args.project and args.project not in projects:
        return _fail(
            f"unknown project {args.project!r}; configured projects: "
            + ", ".join(sorted(projects))
        )
    store = Store()
    selected = {args.project: projects[args.project]} if args.project else projects
    for name, cfg in selected.items():
        print(f"# {name}")
        print(listing.issues_table(cfg, store))
    return 0


def cmd_tasks(args: argparse.Namespace) -> int:
    from pablo import listing

    print(
        listing.tasks_table(
            load_projects(), Store(), live=args.live, refresh=args.refresh
        )
    )
    return 0


def cmd_slack(args: argparse.Namespace) -> int:
    """Print paste-ready Slack mrkdwn for the review and/or QA queues.

    With no ``state`` argument it prints both queues separated by a divider so
    each block can be copied into its own Slack channel. With an explicit
    ``waiting-review`` or ``needs-testing`` it prints just that one block."""
    from pablo import listing

    states = [args.state] if args.state else ["waiting-review", "needs-testing"]
    projects = load_projects()
    store = Store()
    blocks = [listing.render_slack(listing.queue_tasks(projects, store, s), s) for s in states]
    if len(blocks) == 1:
        print(blocks[0])
    else:
        print("\n\n―――― review above · QA below ――――\n\n".join(blocks))
    return 0


def cmd_projects(args: argparse.Namespace) -> int:
    for name, cfg in sorted(load_projects().items()):
        print(f"{name}\t{cfg.type}\t{cfg.provider}\t{cfg.repo_path}")
    return 0


def cmd_doctor(args: argparse.Namespace) -> int:
    from pablo import doctor

    results = doctor.check_all(load_projects())
    print(doctor.render(results))
    return 0 if all(result.ok for result in results) else 1


def cmd_dispatch(args: argparse.Namespace) -> int:
    from pablo import dispatch

    return dispatch.run(load_projects(), Store())


def cmd_poll(args: argparse.Namespace) -> int:
    from pablo import poller

    projects = load_projects()
    if args.project:
        if args.project not in projects:
            return _fail(
                f"unknown project {args.project!r}; configured projects: "
                + ", ".join(sorted(projects))
            )
        projects = {args.project: projects[args.project]}
    store = Store()
    for name, cfg in projects.items():
        events = poller.poll_project(cfg, store)
        for event in events:
            print(f"[{name}] {event}")
    return 0


def cmd_docs(args: argparse.Namespace) -> int:
    """Fetch a Confluence documentation page via `acli confluence page view`.

    Usage: ``pablo docs <page-id | confluence-url> [--project <name>]``.
    The project is optional — used only to resolve ``confluence.space`` for
    scoping hints and to surface a clear error when acli auth (which is
    global per Atlassian account) isn't ready. Prints the page title + URL
    header followed by the storage-format body (XHTML with Confluence
    macros).
    """
    from pablo import confluence

    target = " ".join(args.page).strip()
    if not target:
        return _fail("usage: pablo docs <page-id | confluence-url> [--project <name>]")
    projects = load_projects()
    cfg = None
    if args.project:
        cfg = projects.get(args.project)
        if cfg is None:
            return _fail(
                f"unknown project {args.project!r}; configured projects: "
                + ", ".join(sorted(projects))
            )
    elif projects:
        # Default to the first configured project (config order is stable).
        cfg = next(iter(projects.values()))
    try:
        page = confluence.fetch(target, cfg)
    except PabloError as exc:
        return _fail(str(exc))
    print(f"# {page.title}\n{page.url}\n")
    print(page.body)
    return 0


# ------------------------------------------------- cwd-resolved commands ---


def _repo_slug(cfg: ProjectConfig) -> str:
    from pablo.providers.github import repo_slug

    return repo_slug(cfg)


def _resolve_ctx(store: Store) -> TaskCtx:
    task = store.task_for_cwd(Path.cwd())
    cfg = load_projects().get(task.project)
    if cfg is None:
        raise PabloError(
            f"task {task.branch} belongs to project {task.project!r}, which has "
            f"no config under projects/ anymore"
        )
    return TaskCtx(task=task, cfg=cfg, store=store)


def cmd_state(args: argparse.Namespace) -> int:
    store = Store()
    ctx = _resolve_ctx(store)
    with task_lock(store, ctx.task.project, ctx.task.branch):
        enter_state(ctx, args.state, trigger=not args.no_trigger)
    print(f"{ctx.task.branch}: state set to {ctx.task.state}")
    return 0


def cmd_relaunch(args: argparse.Namespace) -> int:
    """Manually re-fire the current state's agent (and/or startup) launchers.

    Generalizes across all agent-launching states
    (in-progress/ci-red/request-changes/testing-failed): re-fires each label
    in ``LAUNCH_SPECS`` for the task's current state. Used to recover a
    cold-worktree Orca launch hang: the worktree is now warm, so the re-fire
    usually opens the Orca terminal (for ``in-progress`` this also
    auto-renames the worktree to ``OMS-XXXX`` once the analyst sends its
    first message). Non-blocking, same fire-and-forget calls as ``pablo
    start``; safe to run repeatedly. Resets the auto re-fire attempt
    counters so the poller's self-healing budget starts fresh.
    """
    store = Store()
    ctx = _resolve_ctx(store)
    only = args.only
    fired: list[str] = []
    with task_lock(store, ctx.task.project, ctx.task.branch):
        task = ctx.task
        for spec in _specs_for(ctx, task.state):
            if only is not None and spec.label != only:
                continue
            fn, args_ = spec.build(ctx)
            fn(*args_)
            task.agent_launches[spec.label] = {"launched_at": utcnow(), "attempts": 1}
            fired.append(spec.label)
        store.save(task)
    if not fired:
        print(f"{ctx.task.branch}: nothing to relaunch in {ctx.task.state} state"
              + (f" matching --only {only!r}" if only else ""))
        return 0
    print(
        f"{ctx.task.branch}: re-launched {', '.join(fired)} — "
        f"check Orca for the new terminal tab"
    )
    return 0


def cmd_waiting(args: argparse.Namespace) -> int:
    store = Store()
    ctx = _resolve_ctx(store)
    with task_lock(store, ctx.task.project, ctx.task.branch):
        ended_in = toggle_waiting(ctx)
    if ended_in == "waiting":
        print(f"{ctx.task.branch}: paused (waiting); will restore to "
              f"{ctx.task.state_before_waiting}")
    else:
        print(f"{ctx.task.branch}: un-paused, back to {ended_in}")
    return 0


def cmd_skip_ci(args: argparse.Namespace) -> int:
    store = Store()
    ctx = _resolve_ctx(store)
    if ctx.task.state != CI_RED:
        return _fail(
            f"task is {ctx.task.state}, not ci-red — nothing to skip"
        )
    with task_lock(store, ctx.task.project, ctx.task.branch):
        ctx.task.ci_ignored = True
        enter_state(ctx, READY_TO_REVIEW)
    print(f"{ctx.task.branch}: CI results skipped, moved to {ctx.task.state}")
    return 0


def cmd_retrigger_ci(args: argparse.Namespace) -> int:
    store = Store()
    ctx = _resolve_ctx(store)
    repo = _repo_slug(ctx.cfg)
    with task_lock(store, ctx.task.project, ctx.task.branch):
        run_ids = ghpr.rerun_ci(repo, ctx.task.branch)
        if ctx.task.pr_number is not None:
            ghpr.mark_draft(repo, ctx.task.pr_number)
    print(
        f"{ctx.task.branch}: re-triggered CI — {len(run_ids)} workflow run(s) "
        f"({', '.join(run_ids)})"
    )
    return 0


def cmd_close(args: argparse.Namespace) -> int:
    store = Store()
    ctx = _resolve_ctx(store)
    task, cfg = ctx.task, ctx.cfg
    sessions = agents.active_sessions(task.worktree_path)
    if sessions and not args.yes:
        return _fail(
            f"{len(sessions)} agent session(s) still active on this worktree — "
            f"wait for them or re-run with --yes"
        )
    with task_lock(store, task.project, task.branch):
        # The command deletes the worktree we may be standing in: move out
        # first or the shell would be left in a deleted cwd.
        os.chdir(cfg.repo_path)
        gitrepo.remove_worktree(cfg.repo_path, task.worktree_path, task.branch)
        store.delete(task.project, task.branch)
    print(
        f"closed {task.branch} ({task.project}): worktree removed, state cleared. "
        f"The PR itself is untouched. You are now in {cfg.repo_path}."
    )
    return 0


def cmd_precommit_check(args: argparse.Namespace) -> int:
    store = Store()
    try:
        ctx = _resolve_ctx(store)
    except PabloError as exc:
        print(f"pablo: {exc}", file=sys.stderr)
        return 2
    payload = {
        "project": ctx.task.project,
        "branch": ctx.task.branch,
        "state": ctx.task.state,
        "allowed": ctx.task.state in COMMIT_ALLOWED_FROM,
    }
    print(json.dumps(payload))
    return 0


def cmd_task_current(args: argparse.Namespace) -> int:
    store = Store()
    ctx = _resolve_ctx(store)
    payload = ctx.task.to_json()
    payload["repo_path"] = str(ctx.cfg.repo_path)
    payload["primary_branch"] = ctx.cfg.primary_branch
    print(json.dumps(payload, indent=2))
    return 0


def cmd_internal_launch_agent(args: argparse.Namespace) -> int:
    worktree = Path(args.worktree)
    agents._do_launch_agent(worktree, args.agent, args.prompt)
    agents._refresh_agent_display_cache(args.project, args.branch, worktree)
    return 0


def cmd_internal_run_startup_script(args: argparse.Namespace) -> int:
    worktree = Path(args.worktree)
    agents._do_run_startup_script(worktree, Path(args.script))
    agents._refresh_agent_display_cache(args.project, args.branch, worktree)
    return 0


def cmd_watch_agent(args: argparse.Namespace) -> int:
    agents.wait_for_handle(args.handle)
    store = Store()
    with task_lock(store, args.project, args.branch):
        task = store.get(args.project, args.branch)
        if task is None:
            return 0  # task closed while the agent ran; nothing to do
        if args.expect_state and task.state != args.expect_state:
            return 0  # state moved on; the follow-up no longer applies
        if args.then == "pr-draft" and task.pr_number is not None:
            cfg = load_projects().get(args.project)
            if cfg is not None:
                ghpr.mark_draft(_repo_slug(cfg), task.pr_number)
    return 0


# ----------------------------------------------------------------- main ---


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="pablo", description="PABLO — AI orchestrator")
    sub = parser.add_subparsers(dest="command", required=True)

    p_start = sub.add_parser("start", help="start a task (issue URL or --project + prompt)")
    p_start.add_argument("--project", help="project name for plain-prompt tasks")
    p_start.add_argument("input", nargs="+", help="issue URL or task prompt")
    p_start.set_defaults(func=cmd_start)

    p_sync = sub.add_parser("sync", help="sync task worktrees with the primary branch")
    p_sync.add_argument("project", nargs="?", help="limit to one project")
    p_sync.add_argument(
        "--apply", action="store_true",
        help="actually sync (default is a dry-run unless sync.auto_apply is set)",
    )
    p_sync.set_defaults(func=cmd_sync)

    p_rebase_log = sub.add_parser(
        "rebase-log", help="show the last sync/rebase session log"
    )
    p_rebase_log.add_argument("project", nargs="?", help="limit to one project")
    p_rebase_log.set_defaults(func=cmd_rebase_log)

    p_issues = sub.add_parser("issues", help="issues assigned to me, per project")
    p_issues.add_argument("project", nargs="?", help="limit to one project")
    p_issues.set_defaults(func=cmd_issues)

    p_tasks = sub.add_parser("tasks", help="active task worktrees and their states")
    p_tasks.add_argument(
        "--live", action="store_true",
        help="fetch fresh Tracker/PR/Agents data for display only "
             "(does not update the cache the poller maintains)",
    )
    p_tasks.add_argument(
        "--refresh", action="store_true",
        help="fetch fresh Tracker/PR/Agents data and persist it as the new "
             "cache before rendering (an on-demand poll for these tasks)",
    )
    p_tasks.set_defaults(func=cmd_tasks)

    p_slack = sub.add_parser(
        "slack",
        help="paste-ready Slack list of PRs to review and/or QA (waiting-review / needs-testing)",
    )
    p_slack.add_argument(
        "state", nargs="?", choices=["waiting-review", "needs-testing"],
        help="one queue only; omit to print both separated by a divider",
    )
    p_slack.set_defaults(func=cmd_slack)

    p_projects = sub.add_parser("projects", help="list configured projects")
    p_projects.set_defaults(func=cmd_projects)

    p_doctor = sub.add_parser("doctor", help="check required CLIs are installed and authenticated")
    p_doctor.set_defaults(func=cmd_doctor)

    p_dispatch = sub.add_parser(
        "dispatch", help="cron entry point: run due sync/poll jobs for all projects"
    )
    p_dispatch.set_defaults(func=cmd_dispatch)

    p_poll = sub.add_parser("poll", help="run the task-state polling once")
    p_poll.add_argument("project", nargs="?", help="limit to one project")
    p_poll.set_defaults(func=cmd_poll)

    p_docs = sub.add_parser(
        "docs", help="fetch a Confluence documentation page (via acli)"
    )
    p_docs.add_argument("--project", help="scope to a configured project (optional)")
    p_docs.add_argument("page", nargs="+", help="page id or Confluence URL")
    p_docs.set_defaults(func=cmd_docs)

    p_state = sub.add_parser("state", help="force the current task to a state")
    p_state.add_argument("state", help="target state")
    p_state.add_argument(
        "--no-trigger", action="store_true",
        help="skip the state's on-enter actions (ignored for waiting)",
    )
    p_state.set_defaults(func=cmd_state)

    p_relaunch = sub.add_parser(
        "relaunch",
        help="re-fire the current state's agent/startup launchers on the current task",
    )
    p_relaunch.add_argument(
        "--only",
        choices=["task-analyst", "startup-script", "ci-analyst", "pr-feedback", "task-feedback"],
        help="re-fire only one label (default: all labels for the current state)",
    )
    p_relaunch.set_defaults(func=cmd_relaunch)

    p_waiting = sub.add_parser("waiting", help="toggle the waiting pause for the current task")
    p_waiting.set_defaults(func=cmd_waiting)

    p_skip_ci = sub.add_parser("skip-ci", help="ignore failing CI checks and move the current task past ci-red")
    p_skip_ci.set_defaults(func=cmd_skip_ci)

    p_retrigger = sub.add_parser("retrigger-ci", help="re-run all GitHub Actions jobs for the current task")
    p_retrigger.set_defaults(func=cmd_retrigger_ci)

    p_close = sub.add_parser("close", help="close the current task (delete worktree + record)")
    p_close.add_argument("--yes", action="store_true", help="close even if agents are active")
    p_close.set_defaults(func=cmd_close)

    p_pre = sub.add_parser("precommit-check", help="check /commit-and-pr is allowed here")
    p_pre.add_argument("--json", action="store_true", default=True)
    p_pre.set_defaults(func=cmd_precommit_check)

    p_task = sub.add_parser("task", help="task record utilities")
    task_sub = p_task.add_subparsers(dest="task_command", required=True)
    p_task_current = task_sub.add_parser("current", help="dump the current task record")
    p_task_current.add_argument("--json", action="store_true", default=True)
    p_task_current.set_defaults(func=cmd_task_current)

    return parser


def _build_internal_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="pablo", add_help=False)
    sub = parser.add_subparsers(dest="command", required=True)

    p_launch = sub.add_parser("internal-launch-agent")
    p_launch.add_argument("--worktree", required=True)
    p_launch.add_argument("--agent", required=True)
    p_launch.add_argument("--prompt", required=True)
    p_launch.add_argument("--project", required=True)
    p_launch.add_argument("--branch", required=True)
    p_launch.set_defaults(func=cmd_internal_launch_agent)

    p_startup = sub.add_parser("internal-run-startup-script")
    p_startup.add_argument("--worktree", required=True)
    p_startup.add_argument("--script", required=True)
    p_startup.add_argument("--project", required=True)
    p_startup.add_argument("--branch", required=True)
    p_startup.set_defaults(func=cmd_internal_run_startup_script)

    p_watch = sub.add_parser("watch-agent")
    p_watch.add_argument("--project", required=True)
    p_watch.add_argument("--branch", required=True)
    p_watch.add_argument("--handle", required=True)
    p_watch.add_argument("--then", required=True, choices=["pr-draft"])
    p_watch.add_argument("--expect-state")
    p_watch.set_defaults(func=cmd_watch_agent)

    return parser


_INTERNAL_COMMANDS = frozenset({"internal-launch-agent", "internal-run-startup-script", "watch-agent"})


def main(argv: list[str] | None = None) -> int:
    if argv is None:
        argv = sys.argv[1:]
    if argv and argv[0] in _INTERNAL_COMMANDS:
        parser = _build_internal_parser()
    else:
        parser = build_parser()
    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except PabloError as exc:
        return _fail(str(exc))


if __name__ == "__main__":
    sys.exit(main())
