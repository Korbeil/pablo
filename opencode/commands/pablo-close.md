---
description: Ferme la tâche courante (supprime worktree + état) — recours manuel, l'auto-close sur merge est la voie normale
---

Manually close the current PABLO task: delete its worktree and its state
record. This is the escape hatch — the normal path is the automatic close
when the PR is merged. This never merges or closes the GitHub PR itself.

## Context (auto-injected)

Current task:
!`pablo task:info current --json 2>&1`

## Steps

1. If the context above is an error (not a PABLO task worktree), stop and
   show it.
2. Confirm with the user that they really want to close this task
   manually (mention the branch and that the worktree will be deleted;
   remind them a merged PR closes the task automatically).
3. **`cd` to the `repo_path` from the context above first** — the command
   deletes the very worktree we are standing in, and the shell must not
   be left in a deleted directory.
4. Run `pablo task:close` (add `--yes` only if the user explicitly wants to
   close despite active agent sessions).
5. Relay the output or error verbatim.

User request: $ARGUMENTS
