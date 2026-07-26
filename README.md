# P.A.B.L.O.
<i>Personal Assistant Building Local Operations</i>

PABLO is an AI orchestrator for the projects I work on. It is not a
standalone CLI product: it plugs into my existing OpenCode/Orca agent
setup as a set of **OpenCode agents, commands, and skills**, backed by a
small Python engine and an unattended background layer.

**This README is the source of truth** — keep it up to date as the
structure evolves (`docs/specification.md` is the original prompt, not the
reference).

PABLO's philosophy: **agents are read-only and analysis-first**. PABLO
never writes or modifies code, never resolves conflicts, and never mutates
issue trackers (Jira / GitHub Issues / Linear stay strictly read-only). It
*does* perform git and PR-metadata operations — creating branches and
worktrees, rebasing, force-pushing (with lease only), creating draft PRs,
toggling PRs draft/ready, deleting worktrees — but only per the explicit
rules below, never on its own judgement.

---

## Layout

```
pablo/
├── README.md                  ← you are here (source of truth)
├── docs/specification.md      ← original build prompt (amended 2026-07-26:
│                                 CLI preflight is Python, not bash)
├── pyproject.toml             ← Poetry package `pablo` (Python 3.12 + PyYAML)
├── projects/                  ← one YAML per managed project + default.yaml
├── src/pablo/                 ← the engine (see "Engine modules")
├── tests/                     ← pytest suite
├── bin/install.sh             ← symlinks agents/commands/units, enables timer
├── bin/uninstall.sh           ← reverse of install.sh (keeps ~/.pablo data)
├── systemd/                   ← pablo-dispatch.service + .timer (user units)
└── opencode/
    ├── agents/                ← task-analyst.md, task-feedback.md
    └── commands/              ← the /pablo-* command set
```

Everything PABLO creates lives inside this repository. The originals in
`~/.config/opencode/{agents,commands}/` are never modified — `install.sh`
only creates **new symlinks** pointing into this repo (and refuses to
overwrite anything that isn't already such a symlink). Runtime data lives
in `~/.pablo/` (see "Task state storage").

### Engine modules (`src/pablo/`)

| module | responsibility |
|---|---|
| `cli.py` | the `pablo` entry point; every command/timer wraps a subcommand here |
| `config.py` | project YAML loading + per-key defaults merge |
| `model.py` | `Task` / `Issue` records, state-name constants |
| `store.py` | central state store + per-task lock |
| `states.py` | the data-driven state machine (single on-enter handler) |
| `naming.py` | branch naming convention + `-2`/`-3` dedupe |
| `gitrepo.py` | git plumbing: worktrees, lease-safe sync |
| `sync.py` | the per-project worktree-sync job |
| `poller.py` | the per-project state-polling job + merge auto-close |
| `ghpr.py` | GitHub PR plumbing: CI evaluation, review evaluation, draft/ready |
| `agents.py` | launching OpenCode agents via Orca, activity queries |
| `listing.py` | the `pablo issues` / `pablo tasks` terminal tables |
| `dispatch.py` | the cron dispatcher fired by the systemd timer |
| `doctor.py` | CLI preflight checks (`pablo doctor`) |
| `providers/` | one module per issue tracker behind a small interface |

## Installation

```bash
./bin/install.sh
```

which: runs `poetry install`, links the venv's `pablo` into
`~/.local/bin/pablo`, symlinks `opencode/agents/*.md` and
`opencode/commands/*.md` into `~/.config/opencode/`, symlinks + enables
the `pablo-dispatch` systemd **user** timer, and finishes with a
`pablo doctor` run. `./bin/uninstall.sh` reverses it (only removing
symlinks that resolve into this repo; `~/.pablo` data is kept).

**Orca visibility (verified 2026-07-26):** Orca's `--worktree path:`
selector only resolves worktrees of repos **registered in Orca**
(`orca repo add <path>`; registered repos have
`externalWorktreeVisibility: show`). Register each managed project's repo
in Orca so PABLO's agent runs appear as Orca terminals; for unregistered
repos PABLO transparently falls back to headless `opencode run` (see
"Agent running").

## Agents / commands / skills breakdown

Following the conventions of my other OpenCode agents (flat `*.md` files,
YAML frontmatter with `description` / `mode` / `model` / `temperature` /
`permission` maps, `!`cmd`` context injection and `$ARGUMENTS` in
commands):

**Agents** (model-driven analysis flows, strictly read-only):

- `task-analyst` — generalization of my `jira-analyst`: analyzes the
  task's issue (Jira, GitHub Issues, or Linear — dispatched on the
  project's `issue_tracker.provider`) and produces a summary + grounded
  action plan. For plain-prompt tasks (no issue) it applies the same
  methodology to the prompt text. Auto-run once per task on entering
  `in-progress`.
- `task-feedback` — generalization of my `jira-feedback`: analyzes
  QA/testing feedback against what the branch/PR actually ships, produces
  a classified fix plan + draft reply. Auto-run on entering
  `testing-failed`.
- `pr-review-planner` — my existing agent, invoked **by name, as-is**
  (not copied: PABLO only runs it, its content needed no rework).
  Auto-run on entering `request-changes`.

**Commands** (explicit entry points, thin wrappers over the `pablo` CLI):

| command | wraps | purpose |
|---|---|---|
| `/pablo-start` | `pablo start` | start a task from an issue URL or a prompt |
| `/pablo-issues` | `pablo issues` | issues assigned to me, per project |
| `/pablo-tasks` | `pablo tasks` | active worktrees + states listing |
| `/pablo-sync` | `pablo sync` | worktree sync (dry-run by default) |
| `/pablo-state` | `pablo state` | manually force the current task's state |
| `/pablo-waiting` | `pablo waiting` | pause/resume toggle |
| `/pablo-close` | `pablo close` | manual close (escape hatch) |
| `/pablo-doctor` | `pablo doctor` | CLI preflight check |
| `/pablo-commit-and-pr` | (agentic) | commit, push, draft PR, state → `draft` |

**Naming note:** the spec calls the last one `/commit-and-pr`, but my
original `commit-and-pr` command must keep existing untouched for
non-PABLO work and both live in the same flat commands folder — so
PABLO's version is named **`/pablo-commit-and-pr`**.

**Skills**: none yet. Shared logic that would have been "skills" lives in
the Python engine instead, which agents/commands reach through `pablo`
subcommands; if reusable prompt-side knowledge emerges later it goes to
`opencode/skills/<name>/SKILL.md` following the same install-by-symlink
pattern.

## The two layers

**Interactive layer** — the agents/commands above, invoked by me in an
OpenCode session.

**Background layer** — a systemd **user** timer (`systemd/`):

- `pablo-dispatch.timer` fires every 5 minutes (`OnCalendar=*:0/5`,
  `Persistent=true`) and runs `pablo dispatch`.
- The dispatcher holds a global flock (a second invocation exits
  immediately), preflights the required CLIs (aborts loudly if one is
  missing/unauthenticated), then for **each project × {sync, poll}**
  checks a last-run stamp in `~/.pablo/stamps/` against the project's own
  `sync.interval_minutes` / `state_polling.interval_minutes` and runs the
  jobs that are due. A failed job's stamp is not written, so it retries
  on the next tick; one project's failure never blocks the others.
- **Adding/removing a project needs no scheduler change at all** — the
  per-project cadence lives in the YAML, the single timer never changes.
  This is why one dispatcher timer was chosen over per-project units.
- Logs go to the journal: `journalctl --user -u pablo-dispatch.service`.

**Considered and rejected: OpenClaw.** Its built-in scheduled "heartbeat"
polling would map naturally onto the background layer's needs. It was
ruled out because: it's a much heavier piece of infrastructure than needed
here (a persistent self-hosted gateway process with messaging-channel
integrations, versus a cron job calling a few APIs); its skill/plugin
system runs with full operator-level privilege and no sandbox boundary
between a loaded skill and shell/file-system execution; and skills pulled
from its community registry have no cryptographic integrity verification,
which is a documented supply-chain risk. Given PABLO's background layer
already needs shell and API access, adding an unsandboxed, unverified
skill-loading surface on top wasn't worth it, especially stacked on an
already-working OpenCode/Orca agent ecosystem. (Kept here so the
reasoning isn't re-litigated without cause.)

## Provider access: CLI-first, no tokens

Everywhere PABLO talks to an external service it uses that service's CLI —
no MCP servers, no raw API calls, no stored tokens anywhere (there is no
secrets section in the config and none should be added). Each CLI manages
its own authentication; when one isn't ready, PABLO surfaces **that CLI's
own error/instructions** verbatim.

- **GitHub — `gh`** (always required; every project's PR/CI flow goes
  through GitHub regardless of tracker):
  - issues: `gh issue view/list --json`, URL matching against
    `git remote get-url origin`
  - PR lookup: `gh pr list --head <branch> --json number,title,state,isDraft,mergedAt,url`
  - CI: `gh pr view <n> --json statusCheckRollup`
  - reviews + ready-for-review anchor: `gh api graphql` (PR `reviews`
    nodes; `timelineItems(itemTypes: [READY_FOR_REVIEW_EVENT])`)
  - draft/ready toggling: `gh pr ready <n>` / `gh pr ready <n> --undo`
  - failure-signal labels: `gh api repos/<o>/<r>/issues/<pr>/events --paginate`
    (`labeled` events)
  - auth check: `gh auth status`
- **Jira — `jira`** ([ankitpokhrel/jira-cli](https://github.com/ankitpokhrel/jira-cli)):
  `jira issue view <KEY> --raw` (fields + changelog), `jira issue list
  --project <KEY> --assignee <id> --raw`; failure signal = changelog
  transitions into `testing.failure_signal`; auth check: `jira me`.
- **Linear — `linear`** ([schpet/linear-cli](https://github.com/schpet/linear-cli),
  the chosen Linear CLI): `linear issue view <KEY> --json`,
  `linear issue list --assignee <id> --json`; failure signal = history
  transitions into `testing.failure_signal`; auth check:
  `linear auth status`. ⚠️ Not yet installed on this machine — the exact
  subcommand spellings above are the provider's assumptions; verify
  against `linear --help` on first install and adjust
  `src/pablo/providers/linear.py` if they differ.

### CLI preflight — `pablo doctor` / `/pablo-doctor`

A **Python** check (`src/pablo/doctor.py`; the spec originally said bash
and was amended 2026-07-26). Required set is derived from the configured
projects: `gh`, `opencode`, `orca` always (`opencode`/`orca` are PABLO
additions to the spec's set — the agent-runner path needs them); `jira` /
`linear` only when some project uses that provider. Each CLI is checked
for **installed** (PATH) and **authenticated/ready** (its own
status/whoami command), with a per-CLI ✅/❌ line, the CLI's own login
instructions on failure, and a non-zero exit — the dispatcher runs the
same check as its fail-fast guard. Probes are capped at 30s (`orca` has
been observed hanging when invoked outside an interactive session), and
in the **dispatcher** preflight an `orca` failure is soft — a warning,
not an abort — because agent runs fall back to headless `opencode run`;
interactively, `pablo doctor` still reports it as ❌.

## Project configuration

One YAML per project in `projects/` (any filename except `default.yaml`,
which holds the PABLO-wide defaults and is **skipped** when scanning for
projects):

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
  identity: bfontaine        # account used to filter "assigned to me" (required)
  project_key: WK            # required. Jira/Linear: the issue key prefix.
                             # GitHub: used as the branch prefix (no native key).
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
```

**Default-eligible keys** (fall back per key to `projects/default.yaml`
when a project omits them — a project can override just one and inherit
the rest): `sync.strategy`, `sync.auto_apply`, `sync.interval_minutes`,
`state_polling.interval_minutes`, `review.bot_whitelist`. Shipped
defaults: `rebase`, `false`, `30`, `10`, `[]`. New keys added later
should follow the same pattern unless they have no sensible global
default (like `issue_tracker`).

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

## Starting a task — `/pablo-start`

```
/pablo-start https://acme.atlassian.net/browse/XXX-123          # from an issue link
/pablo-start --project wallet-kit "fix callback verification"   # from a prompt
```

(the OpenCode command wraps `pablo start <url>` /
`pablo start --project <name> "<prompt>"`)

- Issue URLs are matched against each project's `issue_tracker` config
  (and the repo's GitHub remote for GitHub issues); no match → error,
  never a guess. Prompt tasks need `--project` (the command asks
  otherwise).
- The worktree is created under `worktrees_root`, branched off
  `origin/<primary_branch>`, named per the convention above. If a task
  already exists for the **same issue** it is reused/reported instead of
  duplicated; an unrelated branch with that name triggers the `-2`/`-3`
  rule.
- The task starts in `in-progress`, which auto-runs `task-analyst` in the
  worktree — **once per task** (`task_analyst_ran` flag in the store;
  re-entering `in-progress` later, e.g. from `waiting`, does not re-run
  it). The `/pablo-tasks` agents column shows when the run has finished.

## Task state

One state per task:
`in-progress` → (`/pablo-commit-and-pr`) → `draft` → `ci-red` /
`ready-to-review` → `waiting-review` → `needs-testing` /
`request-changes` → … → closed on merge. Plus `waiting` (manual pause)
and `testing-failed`.

There is deliberately **no `todo` state** (an unstarted assigned issue
simply has no task/worktree — it only appears in `/pablo-issues`) and
**no `init` state** (creation and the first agent run are part of
entering `in-progress`).

The machine is **data-driven**: every state, its display legend, and its
on-enter action live in one table in `src/pablo/states.py`; the poller's
transition checks live in one table in `src/pablo/poller.py`
(`POLL_CHECKS`). The same `enter_state()` handler is used by the
automatic poller, the commands, and the manual override — on-enter
behavior is never duplicated.

| state | legend | entered by | on-enter action |
|---|---|---|---|
| `in-progress` | 🔨 in-progress | `/pablo-start` (initial) | run `task-analyst` (once per task) |
| `waiting` | ⏸️ waiting | `/pablo-waiting` toggle | save `state_before_waiting` |
| `draft` | 📝 draft | `/pablo-commit-and-pr` | — |
| `ci-red` | 🔴 ci-red | poller: CI failure | — |
| `ready-to-review` | 👀 waiting-review | poller: CI green | `gh pr ready`, then chain to `waiting-review` |
| `waiting-review` | 👀 waiting-review | chained | — |
| `needs-testing` | 🧪 needs-testing | poller: review approved | stamp the failure-signal baseline |
| `request-changes` | 🔁 request-changes | poller: changes/comment | run `pr-review-planner`, then PR → draft |
| `testing-failed` | ❌ testing-failed | poller: failure signal | run `task-feedback`, then PR → draft |

(`ready-to-review` is a momentary pass-through — it renders as
👀 waiting-review if ever caught in the listing.)

### `/pablo-commit-and-pr`

The single, uniform way work (re-)enters `draft`, valid from exactly
**`in-progress`, `ci-red`, `request-changes`, `testing-failed`** (guarded
by `pablo precommit-check`; it refuses outside a PABLO task worktree —
the original `/commit-and-pr` still exists for non-PABLO work). It
commits (house staging/message rules), pushes, creates the GitHub PR **as
a draft** with a French description if none exists, and finishes with
`pablo state draft`. **If there is nothing to commit it stops early and
does nothing** — unless `--force`, which skips only the commit step and
runs the rest (for manually committed work). **There is no git-push
detection anywhere**: a raw `git push` never changes PABLO state.

### `/pablo-waiting` (pause toggle)

First call saves the current state and switches to ⏸️ `waiting`; second
call restores the saved state and runs its normal on-enter actions
(subject to run-once flags; restoring into `needs-testing` keeps the
existing failure-signal baseline so pause-time events are deferred, not
lost). **Forbidden from `request-changes` and `testing-failed`** — also
when forcing `waiting` via `/pablo-state`. While paused: no transition
polling except **merge detection**, and worktree sync keeps running.

### `/pablo-state` (manual override)

`pablo state <state> [--no-trigger]`, cwd-resolved like
`/pablo-waiting` / `/pablo-close`. Forcing a state runs the same on-enter
actions as the automatic transition would; `--no-trigger` skips them
(bookkeeping-only) **except for `waiting`**, whose on-enter (saving the
previous state) is what makes the pause restorable. Typical use: a
reviewer's stale "changes requested" blocks the evaluation while they're
on vacation — force the task past it.

### Post-draft transitions (automatic, polled)

- `draft` → `ci-red` on CI failure; `draft`/`ci-red` → `ready-to-review`
  → `waiting-review` on CI green (marking the PR ready on GitHub);
  CI **pending is not a trigger** — the task just stays put. **No CI
  checks at all counts as green** (otherwise a CI-less project could
  never leave draft).
- `waiting-review` → `ci-red` if CI turns red in review (e.g. a sync
  rebase push); back via the normal green path. The PR never left ready
  status on GitHub, so the review anchor doesn't move and pre-blip
  reviews stay valid.
- **Review evaluation** (`waiting-review` → `needs-testing` /
  `request-changes`): my own reviews never count (replying to a thread
  creates a `COMMENTED` review under my name); bot reviews are ignored
  unless whitelisted in `review.bot_whitelist`; only reviews submitted
  **after GitHub's own last `ready_for_review` timeline event** count
  (never PABLO's internal transitions, and never `reviewDecision`, which
  has no timestamp cutoff); latest review per reviewer wins; a
  changes-requested or plain comment review beats any approval (mixed
  verdicts → `request-changes`).
- `needs-testing` → `testing-failed` when the configured
  `testing.failure_signal` fires — a **new** label application (GitHub)
  or status transition (Jira/Linear) that is newer than both the task's
  entry into `needs-testing` (the baseline) and the last handled event
  (recorded in the store as `last_handled_signal_at`) — so an old label
  never triggers spuriously and continued presence never re-triggers.
  PABLO never removes the label or touches the tracker.
- **Accepted gap (deliberate, don't "fix"):** CI turning red during
  `needs-testing` causes no transition — a broken CI surfaces at merge
  time anyway.
- On entering `request-changes` / `testing-failed`, the corresponding
  agent runs and **once it finishes** a detached watcher
  (`pablo watch-agent`) switches the GitHub PR to draft
  (`gh pr ready --undo`) — skipped if the task already moved on.

## Closing a task

**Primary path — automatic on merge.** The poller detects the merged PR
and closes the task itself: deletes the worktree, removes the store
record. It never merges/closes the PR — that's my normal GitHub flow.
**If agent sessions are still active** on the worktree the close is
deferred: the task stays visible with ✅ merged in the PR column, all
other transition evaluation stops (merged PRs' branches may be gone on
GitHub), and the close completes on a later poll once the agents are
done. Merge detection keeps running even in `waiting`.

**Fallback — `/pablo-close`**, run from inside the worktree: the escape
hatch for abandoning/cleaning up. It refuses while agents are active
(`--yes` overrides), and `cd`s to the project's `repo_path` before
deleting the worktree so the shell isn't left in a deleted cwd.

## Worktree sync

Per project, on its own `sync.interval_minutes` cadence (plus `/pablo-sync`
manually): discovers task worktrees via `git worktree list` (+ stray
directories under `worktrees_root`, reported as ❓ unregistered), and for
each one, **regardless of PABLO state**:

1. fetch; **integrate the branch's own remote first** (a collaborator's
   push is never clobbered and won't trip the lease);
2. rebase or merge onto `origin/<primary_branch>` per `sync.strategy`;
3. if history was rewritten and the branch exists remotely, push with
   **`--force-with-lease`** (never plain `--force`); a lease failure
   rolls the worktree back and retries next cycle.

Default is a **dry-run report**; changes are applied only with
`sync.auto_apply: true` or an explicit `--apply` (the cron run does not
imply apply). Dirty worktrees are skipped (✋). **Conflicts are never
auto-resolved**: the sync aborts cleanly and reports the worktree/branch,
the conflicting files, and a hunk summary. (Trivial-case auto-resolution,
e.g. lockfiles, would be an explicit per-project opt-in later — not a
default.)

Sync and the state poller never interleave on a task: both take the
per-task lock (see below); a locked task is skipped (🔒) and retried next
cycle.

## Task state storage

Central store owned by PABLO's installation — never inside a project repo
or worktree:

```
~/.pablo/
├── state/<project>/<branch>.json   # one task record per file
├── state/<project>/<branch>.lock   # per-task lock file
├── stamps/<project>.{sync,poll}    # dispatcher last-run stamps (+ dispatch.lock)
├── agents/                         # headless-run pidfiles + logs
└── worktrees/<repo-dir-name>/      # default worktrees_root
```

A task record holds: `project`, `branch`, `worktree_path`, `state`,
`state_entered_at`, `issue` (provider/key/url/title/status; `null` for
prompt tasks), `summary` + `prompt` (prompt tasks), `state_before_waiting`,
`task_analyst_ran`, `needs_testing_entered_at`, `last_handled_signal_at`,
`pr_number`, `merged`, `created_at`, `updated_at` — UTC ISO-8601
timestamps, atomic writes (tmp + rename).

**Per-task lock**: `flock(2)` on the `.lock` file, taken by sync, the
poller, and every state-changing command. The kernel releases it if the
holder crashes (this natively satisfies the spec's "holder + timestamp
with staleness timeout" goal — holder metadata is still written into the
file for observability). Acquisition times out with a clear error instead
of wedging.

## Agent running & activity (Orca CLI)

The background layer never builds its own agent-invocation mechanism — it
uses the **Orca CLI**, the same runner path as my other Orca-managed
agents:

```bash
# launch (src/pablo/agents.py):
orca terminal create --worktree path:<worktree> \
     --title "pablo:<agent>" \
     --command "opencode run --agent <agent> '<prompt>'" --json
# completion (the pablo watch-agent watcher):
orca terminal wait --terminal <handle> --for exit --timeout-ms 3600000 --json
# activity (task listing + close checks):
orca worktree ps --limit 200 --json     # per-worktree agents[] with state
```

Activity mapping: an agent whose `state` is `working`/`running` counts as
🏃 running; anything else (idle / awaiting input) counts as ⏳ waiting on
feedback. **Fallback:** when Orca is unreachable or doesn't know the
worktree's repo (see "Orca visibility" under Installation), PABLO runs
`opencode run --agent <agent> --dir <worktree>` headless, tracked via
pidfiles in `~/.pablo/agents/` (headless runs always count as running —
they have no idle signal).

## Listings

**`/pablo-issues [project]`** — issues assigned to me (via
`issue_tracker.identity`), one table per project: issue key + title,
tracker status, related PR (matched via the branch convention incl.
`-2`/`-3` variants), local branch. Cells show `-` when nothing matches —
no guessing. Read-only.

**`/pablo-tasks`** — active tasks, columns in order: ① branch (project)
② PABLO state ③ linked issue + title, or the ≤5-word summary for prompt
tasks ④ the tracker's own status (`N/A` for prompt tasks) ⑤ related PR:
📬 open · 📪 draft · ✅ merged (✅ only appears when auto-close is
deferred behind running agents — normally a merged task is already gone
from the listing) ⑥ number of agent sessions on the worktree ⑦ their
activity, e.g. `🏃 1 · ⏳ 1`. State legend: 🔨 in-progress · ⏸️ waiting ·
📝 draft · 🔴 ci-red · 👀 waiting-review · 🧪 needs-testing ·
🔁 request-changes · ❌ testing-failed.

## Development

```bash
poetry install && poetry run pytest              # full test suite
pablo --help                                     # engine subcommands
journalctl --user -u pablo-dispatch.service -f   # background layer logs
```
