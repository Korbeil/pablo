# Task state machine

## Starting a task — `pablo task:start`

```
pablo task:start https://acme.atlassian.net/browse/XXX-123          # from an issue link
pablo task:start --project wallet-kit "fix callback verification"   # from a prompt
```

(the OpenCode command wraps `pablo task:start <url>` /
`pablo task:start --project <name> "<prompt>"`)

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
  it). The `pablo task:list` agents column shows when the run has finished.
- If the project config sets `startup_script`
  ([configuration.md](configuration.md)), it also runs — once per task,
  in its own Orca terminal, in parallel with `task-analyst` (neither waits
  on the other). Guarded independently via the `startup_script_ran` flag.
  The terminal runs `bash <script>; exec bash` so when the script
  finishes the tab drops into an interactive shell at the worktree root
  (showing the script's output) instead of auto-closing on PTY EOF.
- Both launches are asynchronous: entering `in-progress` spawns a detached
  `pablo internal:launch-agent`/`internal:run-startup-script` subprocess
  per launch and returns immediately, so `pablo task:state in-progress` (and
  `pablo task:state`) never blocks on Orca — even if Orca is slow, hung, or
  still indexing a just-created worktree.
- Each detached launcher issues a single
  `orca terminal create --worktree path:<NEW>` call (60s timeout) and
  falls back to a headless `opencode run` if Orca refuses the `path:`
  selector or hangs. A per-worktree `flock` serialises the two launchers'
  `terminal create` spans so they don't race on the same cold path —
  different worktrees still run in parallel as before.

- **Self-healing for a cold-worktree hang.** The launchers are
  fire-and-forget, but a genuine Orca `terminal create` hang leaves the
  `agent_launches` record set with no session ever appearing. The poller
  detects this for **every agent-launching state** (`in-progress`,
  `ci-red`, `request-changes`, `testing-failed`): once `LAUNCH_WINDOW_S`
  (> the detached retry budget) has elapsed with no `active_sessions`
  observed and the attempt budget (`LAUNCH_MAX_ATTEMPTS`) isn't
  exhausted, it re-fires each of the state's detached launchers (the
  worktree is now warm, so the retry usually succeeds in Orca). For
  `in-progress` the Orca workspace `displayName` is also re-set on the
  same heal pass (via `orca worktree set --display-name`), so it shows
  `OMS-XXXX` up front instead of the lowercase branch that Orca
  auto-derives from the path; `cmd_start` sets it eagerly too, but that
  call may lose the same indexing race. The
  `in-progress` startup script is included too (verified safely
  re-runnable); a future project's non-idempotent startup script must be
  scoped out or fixed, not special-cased in the poller. Recover manually
  with `pablo task:relaunch` (see `agents-commands.md`). Post-draft states
  still fall through to `POLL_CHECKS` after the heal, so e.g. a `ci-red`
  that went green transitions normally.

## Task state

One state per task:
`in-progress` → (`/pablo-commit-and-pr`) → `draft` → `ci-red` /
`ready-to-review` → `waiting-review` → `needs-testing` /
`request-changes` → … → closed on merge. Plus `waiting` (manual pause)
and `testing-failed`.

There is deliberately **no `todo` state** (an unstarted assigned issue
simply has no task/worktree — it only appears in the issue listing) and
**no `init` state** (creation and the first agent run are part of
entering `in-progress`).

The machine is **data-driven**: every state, its display legend, and its
on-enter action live in one table in `src/StateMachine/StateMachine.php`; the poller's
transition checks live in one table in `src/Poller/Poller.php`
(`POLL_CHECKS`). The same `enter_state()` handler is used by the
automatic poller, the commands, and the manual override — on-enter
behavior is never duplicated.

| state | legend | entered by | on-enter action |
|---|---|---|---|
| `in-progress` | 🔨 in-progress | `pablo task:start` (initial) | run `task-analyst` (once per task); run `startup_script` if configured (once per task, parallel) |
| `waiting` | ⏸️ waiting | `pablo task:waiting` toggle | save `state_before_waiting` |
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
by `pablo task:precommit-check`; it refuses outside a PABLO task worktree —
the original `/commit-and-pr` still exists for non-PABLO work). It
commits (house staging/message rules), pushes, creates the GitHub PR **as
a draft** with a French description if none exists, and finishes with
`pablo task:state draft`. **If there is nothing to commit it stops early and
does nothing** — unless `--force`, which skips only the commit step and
runs the rest (for manually committed work). **There is no git-push
detection anywhere**: a raw `git push` never changes PABLO state.

### `pablo task:waiting` (pause toggle)

First call saves the current state and switches to ⏸️ `waiting`; second
call restores the saved state and runs its normal on-enter actions
(subject to run-once flags; restoring into `needs-testing` keeps the
existing failure-signal baseline so pause-time events are deferred, not
lost). **Forbidden from `request-changes` and `testing-failed`** — also
when forcing `waiting` via `pablo task:state`. While paused: no transition
polling except **merge detection**, and worktree sync keeps running.

### `pablo task:state` (manual override)

`pablo task:state <state> [--no-trigger]`, cwd-resolved like
`pablo task:waiting` / `pablo task:close`. Forcing a state runs the same on-enter
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
  (`pablo internal:watch-agent`) switches the GitHub PR to draft
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
`task_analyst_ran` + `startup_script_ran` (run-once guards for re-entering
`in-progress`), `agent_launches` (per-label `{launched_at, attempts}` map
tracking the fire-and-forget launches for the poller's cold-worktree
self-healing — see above), `needs_testing_entered_at`, `last_handled_signal_at`,
`last_seen_issue_status` (observed-transition fallback baseline),
`pr_number`, `merged`, `created_at`, `updated_at` — UTC ISO-8601
timestamps, atomic writes (tmp + rename).

**Per-task lock**: `flock(2)` on the `.lock` file, taken by sync, the
poller, and every state-changing command. The kernel releases it if the
holder crashes (this natively satisfies the spec's "holder + timestamp
with staleness timeout" goal — holder metadata is still written into the
file for observability). Acquisition times out with a clear error instead
of wedging.
