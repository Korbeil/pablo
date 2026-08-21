# Project configuration & branch naming

## Project configuration

One YAML per project in `~/.pablo/projects/` (any filename). Use
`pablo project:new` to scaffold one interactively, or write it by hand (copy
`docs/examples/acme-pim.yaml`). Remove one with `pablo project:remove <name>`
(resolved via the YAML `name` key): it refuses while the project still has
active tasks — close them first with `pablo task:close` — deletes only the
YAML (worktrees and `~/.pablo` state stay), and always re-runs agent
generation since the enabled provider set may have shrunk. PABLO-wide
defaults live separately in
`~/.pablo/config.yaml` (see "Default-eligible keys" below):

```yaml
name: wallet-kit             # unique project name (required)
type: open-source            # work | open-source | personal (required)
repo:
  path: ~/dev/wallet-kit     # the main checkout (required)
  primary_branch: main       # (required)
worktrees_root: ~/dev/wallet-kit-worktrees
                             # optional; default ~/.pablo/worktrees/<repo-dir-name>/
issue_tracker:
  provider: github           # github | jira | linear (required)
identity: bfontaine        # account used to filter "assigned to me" (required;
                              # informational for jira — acli uses currentUser())
  project_key: WK            # required. Jira/Linear: the issue key prefix.
                              # GitHub: used as the branch prefix (no native key).
  site: acme.atlassian.net   # jira only, optional: which Atlassian site host
                              # to use for browse URLs / Confluence page URLs;
                              # default = "jira"
  repo: acme/upstream        # github only, optional: repo where issues and PRs
                              # live (e.g. the upstream of a fork). Default = the
                              # origin remote of repo.path. Set this when you work
                              # on a fork but file/open PRs against the upstream.
confluence:                    # optional; only relevant for jira projects (acli
  space: PIM                   # owns both Jira and Confluence auth). Scopes doc
                              # lookups (agents) to this space key.
sync:
  strategy: rebase           # rebase | merge
  auto_apply: false          # false → cron sync is dry-run/report-only
  interval_minutes: 30       # cadence of the cron-driven sync for this project
state_polling:
  interval_minutes: 10       # cadence of the cron-driven state polling
testing:
  failure_signal: qa-failed  # GitHub: a label name watched on the PR.
                             # Jira/Linear: a status value watched on the issue.
                             # optional; without it needs-testing → testing-failed
                             # never triggers automatically
review:
  bot_whitelist: []          # bot accounts whose PR reviews count anyway,
                             # e.g. ["copilot-pull-request-reviewer[bot]"]
ci:
  ignore_checks: []          # substrings matched against a check's name/workflow/context
                             # to exclude it from CI evaluation, e.g. ["approval"] for a
                             # CircleCI manual-approval gate that nobody will click and
                             # therefore sits pending (or action_required) forever
startup_script: ~/scripts/pablo-setup.sh
                             # optional; absolute path to a bash script run once per
                             # task, in its own Orca terminal, alongside task-analyst
                             # on pablo task:start. Default: null (skipped). Launched via a
                             # detached subprocess, so it never blocks the pablo task:start
                             # or pablo task:state command that triggers it.
default_model: openrouter/deepseek/deepseek-v4-flash-0731
                             # optional; the agent model used for this project's tasks.
                             # Falls back to the global default_model in ~/.pablo/config.yaml.
pr_description_locale: en   # optional; locale used when drafting PR descriptions for this
                             # project. Falls back to the global pr_description_locale in
                             # ~/.pablo/config.yaml.
```

**Default-eligible keys** (fall back per key to `~/.pablo/config.yaml`
when a project omits them — a project can override just one and inherit
the rest): `sync.strategy`, `sync.auto_apply`, `sync.interval_minutes`,
`state_polling.interval_minutes`, `review.bot_whitelist`,
`ci.ignore_checks`, `default_model`, `pr_description_locale`. Shipped
defaults: `rebase`, `false`, `30`, `10`, `[]`, `[]`; `default_model` and
`pr_description_locale` have no built-in value — each must be set either
per project or globally, otherwise loading the project fails. New keys
added later should follow the same pattern unless they have no sensible
global default (like `issue_tracker`).

### `agent_backend` (global only)

A cross-project key in `~/.pablo/config.yaml` (not per-project, and not in
the default-eligible set): selects which agent runner PABLO uses to launch
agents on task worktrees. Values: `orca` (default) or `openchamber`. It is
resolved at runtime (like `default_model`) and can also be set with the
`PABLO_AGENT_BACKEND` env var. When it differs from `orca`, `pablo
system:doctor`/`system:setup` also check the `openchamber` CLI. See
[background-layer.md](background-layer.md#openchamber-backend-optional).

## Branch naming convention

Branches created or matched by PABLO always follow the fixed pattern:

```
[project-key]-[issue-id]
```

lowercased, e.g. `xxx-123` for Jira issue `XXX-123`.

- **Jira / Linear**: `project-key` is the issue's own project key (e.g.
  `XXX`), lowercased. `issue-id` is the numeric/short ID from the issue
  key (e.g. `123` from `XXX-123`).
- **GitHub**: since GitHub has no native project key, use the project's
  `issue_tracker.project_key` config value as the prefix, and the issue
  number as `issue-id` (e.g. `project_key: WK` + issue #45 → `wk-45`).
- No slug or title text is appended — the branch name is just the two
  parts above.
- **Duplicates**: if a branch matching `[project-key]-[issue-id]` already
  exists (e.g. `xxx-123`), don't reuse or overwrite it. Append `-2`,
  `-3`, etc. — trying each in order — until an unused branch name is
  found (e.g. `xxx-123-2`, then `xxx-123-3`). This applies both when
  creating a new worktree and any other place PABLO generates a branch
  name for a task.
- Plain-prompt tasks (no issue) use `project_key` + a short slug of the
  prompt instead (e.g. `xxx-fix-callback-verification`), same dedupe rule.
- **Exception**: if a free-text prompt mentions a recognizable Jira/Linear
  issue key belonging to a configured project (e.g. "fix the thing per
  OMS-6393"), PABLO resolves that issue and uses the issue-linked
  `[project-key]-[issue-id]` name instead of a word slug — same as
  passing the issue URL directly. GitHub is excluded from this detection
  (its `project_key` is just a configured branch prefix, not part of how
  GitHub issues are referenced). Only prompts with no detectable key fall
  back to the word-slug name.
