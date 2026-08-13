# Agents, commands, skills

Following the conventions of the user's other OpenCode agents (flat
`*.md` files, YAML frontmatter with `description` / `mode` / `model` /
`temperature` / `permission` maps, `` !cmd `` context injection and
`$ARGUMENTS` in commands):

## Agents (model-driven analysis flows, strictly read-only)

- `task-analyst` — generalization of `jira-analyst`: analyzes the task's
  issue (Jira, GitHub Issues, or Linear — dispatched on the project's
  `issue_tracker.provider`) and produces a summary + grounded action
  plan. For plain-prompt tasks (no issue) it applies the same
  methodology to the prompt text. Auto-run once per task on entering
  `in-progress`.
- `task-feedback` — generalization of `jira-feedback`: analyzes
  QA/testing feedback against what the branch/PR actually ships, produces
  a classified fix plan + draft reply. Auto-run on entering
  `testing-failed`.
- `pr-feedback` — generalization of `pr-review-planner`: reads the PR's
  unresolved review comments and review bodies, produces a topic-grouped,
  actionable fix plan. Auto-run on entering `request-changes`. Unlike
  `pr-review-planner`, this is a PABLO-owned, in-repo copy (not invoked
  by name from `~/.config/opencode/agents/`) so it can take the PR number
  PABLO already knows instead of re-deriving it.
- `ci-analyst` — new agent, no external source: pulls the PR's failing CI
  checks and their job logs (`gh pr checks`, `gh run view --log-failed`),
  maps each failure to the responsible code, and produces a fix plan.
  Auto-run on entering `ci-red`. Read-only and side-effect-free — unlike
  `pr-feedback`/`task-feedback`, it never flips the PR to draft (CI
  turning red never changes the PR's ready status on GitHub).

## Commands

All PABLO operations are invoked via the `pablo` CLI directly.
Key subcommands:

- `pablo task:start` — start a task from an issue URL or a prompt
- `pablo show:issues` — issues assigned to me, per project
- `pablo show:tasks` — active worktrees + states listing
- `pablo sync:run` — worktree sync (dry-run by default)
- `pablo task:state` — manually force the current task's state
- `pablo task:waiting` — pause/resume toggle
- `pablo task:relaunch` — re-fire the current state's agent/startup launchers
- `pablo task:close` — manual close (escape hatch)
- `pablo system:doctor` — CLI preflight check
- `pablo archive:backup` — write a restorable archive of all `~/.pablo` state
- `pablo archive:restore` — restore state/configs and recreate task worktrees
- `pablo task:retrigger-ci` — re-run all CI workflow jobs for the current task
- `pablo task:skip-ci` — skip failing CI checks, move past `ci-red`
- `pablo show:docs` — fetch a Confluence documentation page
- `pablo show:prs` — paste-ready Slack PR lists for review/QA

There is one OpenCode command:

- **`/pablo-commit-and-pr`** — commit, push, draft PR, state → `draft`.
  This is the only PABLO operation that still runs as an OpenCode command
  (it needs direct git/gh access that a thin `pablo` CLI wrapper can't provide).

**Naming note:** the spec calls the last one `/commit-and-pr`, but the
user's original `commit-and-pr` command must keep existing untouched for
non-PABLO work and both live in the same flat commands folder — so
PABLO's version is named **`/pablo-commit-and-pr`**.

## Skills

None yet. Shared logic that would have been "skills" lives in the PABLO
engine instead, which agents/commands reach through `pablo` subcommands;
if reusable prompt-side knowledge emerges later it goes to
`opencode/skills/<name>/SKILL.md` following the same install-by-symlink
pattern.
