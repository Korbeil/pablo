# Task state machine

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
  `origin/<primary_branch>`, named per the
  [branch naming convention](configuration.md#branch-naming-convention).
  If a task already exists for the **same issue** it is reused/reported
  instead of duplicated; an unrelated branch with that name triggers the
  `-2`/`-3` rule.
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
| `ci-red` | 🔴 ci-red | poller: CI failure | run `ci-analyst` |
| `ready-to-review` | 👀 waiting-review | poller: CI green | `gh pr ready`, then chain to `waiting-review` |
| `waiting-review` | 👀 waiting-review | chained | — |
| `needs-testing` | 🧪 needs-testing | poller: review approved | stamp the failure-signal baseline |
| `request-changes` | 🔁 request-changes | poller: changes/comment | run `pr-feedback`, then PR → draft |
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
  `request-changes`): the user's own reviews never count (replying to a
  thread creates a `COMMENTED` review under their name); bot reviews are
  ignored unless whitelisted in `review.bot_whitelist`; only reviews
  submitted **after GitHub's own last `ready_for_review` timeline event**
  count (never PABLO's internal transitions, and never `reviewDecision`,
  which has no timestamp cutoff); latest review per reviewer wins; a
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

## Task state storage

Central store owned by PABLO's installation — never inside a project
repo or worktree:

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
`last_seen_issue_status` (observed-transition fallback baseline),
`pr_number`, `merged`, `created_at`, `updated_at` — UTC ISO-8601
timestamps, atomic writes (tmp + rename).

**Per-task lock**: `flock(2)` on the `.lock` file, taken by sync, the
poller, and every state-changing command. The kernel releases it if the
holder crashes (this natively satisfies the spec's "holder + timestamp
with staleness timeout" goal — holder metadata is still written into the
file for observability). Acquisition times out with a clear error instead
of wedging.
