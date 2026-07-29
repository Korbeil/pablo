---
description: Ignorer les tests CI qui échouent et passer la tâche au-delà de ci-red
---

Skip failing CI checks for the current task (run from inside its
worktree, like /pablo-state and /pablo-close).

Only valid from the `ci-red` state — refuses if the task is in any
other state (there is nothing to skip).

## Steps

1. Run `pablo skip-ci` from the current directory.
2. Relay the output verbatim.

After this the task moves through `ready-to-review` → `waiting-review`
automatically, and the CI-red check is silenced until the task
re-enters `draft` (via `/pablo-commit-and-pr`).

User request: $ARGUMENTS
