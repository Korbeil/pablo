"""The ``pablo`` command-line entry point.

Interactive OpenCode commands (/pablo-start, /pablo-state, ...) and the
background systemd timer are all thin wrappers over these subcommands; the
deterministic logic lives in the pablo package, never in the markdown.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from pathlib import Path

from pablo import PabloError, agents, ghpr, gitrepo, naming
from pablo.config import ProjectConfig, load_projects
from pablo.model import IN_PROGRESS, Issue, Task
from pablo.providers import get_provider
from pablo.states import COMMIT_ALLOWED_FROM, TaskCtx, enter_state, toggle_waiting
from pablo.store import Store, task_lock

SUMMARY_MAX_WORDS = 5


def _fail(message: str) -> int:
    print(f"pablo: {message}", file=sys.stderr)
    return 1


# ---------------------------------------------------------------- start ---


def _match_issue_url(url: str, projects: dict[str, ProjectConfig]):
    for cfg in projects.values():
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


def cmd_start(args: argparse.Namespace) -> int:
    projects = load_projects()
    store = Store()
    text = " ".join(args.input).strip()
    if not text:
        return _fail("usage: pablo start <issue-url> | pablo start --project <name> \"<prompt>\"")

    if text.startswith(("http://", "https://")):
        matched = _match_issue_url(text, projects)
        if matched is None:
            return _fail(
                f"no managed project matches this issue URL: {text} — check the "
                f"issue_tracker config of your projects"
            )
        cfg, provider, ref = matched
        issue = provider.get_issue(ref, cfg)
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
        print(
            f"started {issue.key} ({issue.title}) in project {cfg.name}\n"
            f"worktree: {task.worktree_path} (branch {branch})\n"
            f"state: {task.state} — task-analyst is running"
        )
        return 0

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

    p_state = sub.add_parser("state", help="force the current task to a state")
    p_state.add_argument("state", help="target state")
    p_state.add_argument(
        "--no-trigger", action="store_true",
        help="skip the state's on-enter actions (ignored for waiting)",
    )
    p_state.set_defaults(func=cmd_state)

    p_waiting = sub.add_parser("waiting", help="toggle the waiting pause for the current task")
    p_waiting.set_defaults(func=cmd_waiting)

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

    p_watch = sub.add_parser("watch-agent", help="internal: wait for an agent run, then follow up")
    p_watch.add_argument("--project", required=True)
    p_watch.add_argument("--branch", required=True)
    p_watch.add_argument("--handle", required=True)
    p_watch.add_argument("--then", required=True, choices=["pr-draft"])
    p_watch.add_argument("--expect-state")
    p_watch.set_defaults(func=cmd_watch_agent)

    return parser


def main(argv: list[str] | None = None) -> int:
    parser = build_parser()
    args = parser.parse_args(argv)
    try:
        return args.func(args)
    except PabloError as exc:
        return _fail(str(exc))


if __name__ == "__main__":
    sys.exit(main())
