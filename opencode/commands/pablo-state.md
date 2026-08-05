---
description: Force l'état PABLO de la tâche courante (répertoire courant = worktree)
---

Manually force the current task's PABLO state. Operates on whatever task
worktree the session is currently in — no branch argument.

## Steps

1. Run `pablo task:state $ARGUMENTS` from the current directory. `$ARGUMENTS`
   is the target state (`in-progress`, `waiting`, `draft`, `ci-red`,
   `ready-to-review`, `waiting-review`, `needs-testing`,
   `request-changes`, `testing-failed`), optionally followed by
   `--no-trigger` to skip the state's automatic on-enter actions
   (`--no-trigger` is ignored for `waiting`).
2. Relay the output or the error verbatim. Notable refusals to pass
   through unchanged: unknown state, not inside a PABLO task worktree,
   and forcing `waiting` from `request-changes`/`testing-failed` (which
   is impossible).

Forcing a state runs the same on-enter actions as the automatic
transitions (e.g. forcing `request-changes` launches `pr-review-planner`
and switches the PR to draft) — warn the user if they seem to expect a
bookkeeping-only change and didn't pass `--no-trigger`.

User request: $ARGUMENTS
