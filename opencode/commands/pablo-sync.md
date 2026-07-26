---
description: Synchronise les worktrees PABLO avec la branche principale (dry-run par défaut)
---

Run PABLO's worktree sync and relay its report.

## Steps

1. Run `pablo sync $ARGUMENTS` (arguments may name a project and/or
   `--apply`; without `--apply` this is a dry-run unless a project has
   `sync.auto_apply: true`).
2. Relay the report verbatim. For ⚠️ conflicts, show the affected
   worktree/branch, the conflicting files, and the hunk summary exactly as
   reported.
3. **Never resolve conflicts yourself** — no edits, no `git rebase
   --continue`, no commits. PABLO leaves conflicted worktrees untouched;
   resolution is always the user's call.

User request: $ARGUMENTS
