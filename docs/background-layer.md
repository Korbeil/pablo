# The two layers, sync, closing, agent execution

## The two layers

**Interactive layer** — the agents/commands (see
[agents-commands.md](agents-commands.md)), invoked by the user in an
OpenCode session.

**Background layer** — a 5-minute scheduler per platform (systemd user
timer on Linux, launchd LaunchAgent on macOS — see
[installation.md](installation.md)) running `pablo dispatch`:

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
ruled out because: it's a much heavier piece of infrastructure than
needed here (a persistent self-hosted gateway process with
messaging-channel integrations, versus a cron job calling a few APIs);
its skill/plugin system runs with full operator-level privilege and no
sandbox boundary between a loaded skill and shell/file-system execution;
and skills pulled from its community registry have no cryptographic
integrity verification, which is a documented supply-chain risk. Given
PABLO's background layer already needs shell and API access, adding an
unsandboxed, unverified skill-loading surface on top wasn't worth it,
especially stacked on an already-working OpenCode/Orca agent ecosystem.
(Kept here so the reasoning isn't re-litigated without cause.)

## Worktree sync

Per project, on its own `sync.interval_minutes` cadence (plus
`/pablo-sync` manually): discovers task worktrees via `git worktree list`
(+ stray directories under `worktrees_root`, reported as ❓
unregistered), and for each one, **regardless of PABLO state**:

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
per-task lock (see [state-machine.md](state-machine.md#task-state-storage)); a
locked task is skipped (🔒) and retried next cycle.

## Closing a task

**Primary path — automatic on merge.** The poller detects the merged PR
and closes the task itself: deletes the worktree, removes the store
record. It never merges/closes the PR — that's the user's normal GitHub
flow. **If agent sessions are still active** on the worktree the close is
deferred: the task stays visible with ✅ merged in the PR column, all
other transition evaluation stops (merged PRs' branches may be gone on
GitHub), and the close completes on a later poll once the agents are
done. Merge detection keeps running even in `waiting`.

**Fallback — `/pablo-close`**, run from inside the worktree: the escape
hatch for abandoning/cleaning up. It refuses while agents are active
(`--yes` overrides), and `cd`s to the project's `repo_path` before
deleting the worktree so the shell isn't left in a deleted cwd.

## Agent running & activity (Orca CLI)

The background layer never builds its own agent-invocation mechanism — it
uses the **Orca CLI**, the same runner path as the user's other
Orca-managed agents:

```bash
# launch (src/pablo/agents.py):
orca worktree list --json                                       # warm-up: wait for the path to be indexed
orca terminal create --worktree path:<worktree> \
     --title "pablo:<agent>" --focus \
     --command "opencode <worktree> --agent <agent> --prompt '<prompt>'" --json
# completion (the pablo watch-agent watcher):
orca terminal wait --terminal <handle> --for exit --timeout-ms 3600000 --json
# activity (task listing + close checks):
orca worktree ps --limit 200 --json     # per-worktree agents[] with state
```

Activity mapping: an agent whose `state` is `working`/`running` counts as
🏃 running; anything else (idle / awaiting input) counts as ⏳ waiting on
feedback. **Fallback:** when Orca is unreachable or doesn't know the
worktree's repo (see [installation.md](installation.md#orca-visibility-verified-2026-07-26)),
PABLO runs `opencode run --agent <agent> --dir <worktree>` headless,
tracked via pidfiles in `~/.pablo/agents/` (headless runs always count as
running — they have no idle signal).
