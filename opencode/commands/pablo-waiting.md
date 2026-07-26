---
description: Met en pause / reprend la tâche courante (toggle waiting)
---

Toggle the `waiting` pause for the current task (run from inside its
worktree, like /pablo-state and /pablo-close).

## Steps

1. Run `pablo waiting` from the current directory.
2. Relay the output verbatim:
   - First call: the task saves its current state and switches to
     ⏸️ waiting. While paused, state polling skips it (except merge
     detection) and worktree sync keeps running.
   - Second call: the task is restored to the saved state and its normal
     on-enter actions run (subject to run-once flags).
   - From `request-changes` or `testing-failed` the toggle refuses — that
     is by design, pass the refusal message through unchanged.

User request: $ARGUMENTS
