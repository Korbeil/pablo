"""The ``pablo`` command-line entry point.

Interactive OpenCode commands (/pablo-start, /pablo-state, ...) and the
background systemd timer are all thin wrappers over these subcommands; the
deterministic logic lives in the pablo package, never in the markdown.
"""

from __future__ import annotations

import argparse
import sys
from pathlib import Path

from pablo import PabloError, agents, gitrepo, naming
from pablo.config import ProjectConfig, load_projects
from pablo.model import IN_PROGRESS, Issue, Task
from pablo.providers import get_provider
from pablo.states import TaskCtx, enter_state
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


# ----------------------------------------------------------------- main ---


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(prog="pablo", description="PABLO — AI orchestrator")
    sub = parser.add_subparsers(dest="command", required=True)

    p_start = sub.add_parser("start", help="start a task (issue URL or --project + prompt)")
    p_start.add_argument("--project", help="project name for plain-prompt tasks")
    p_start.add_argument("input", nargs="+", help="issue URL or task prompt")
    p_start.set_defaults(func=cmd_start)

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
