---
description: Démarre une tâche PABLO (worktree + task-analyst) depuis un lien d'issue ou un prompt
---

Start a new PABLO task. The user input is either an issue URL (Jira,
GitHub, Linear) or a free-text task prompt.

## Context (auto-injected)

Configured projects:
!`pablo projects`

## Steps

1. Look at `$ARGUMENTS`:
   - If it is an **issue URL** (starts with http): run
     `pablo start <url>` exactly as given.
   - If it is a **free-text prompt**: it needs a target project. If the
     user already named one (e.g. "--project wallet-kit" or "on
     wallet-kit"), run `pablo start --project <name> "<prompt>"`.
     Otherwise ask the user which project (list the configured projects
     from the context above), then run the command.
2. Relay the command's output verbatim — it reports the worktree path, the
   branch, and that `task-analyst` is running. If the command fails (no
   matching project, unauthenticated CLI...), show its error message
   as-is; do not work around it.

The command only creates the worktree/branch and starts the analysis agent
— never open an editor or touch any code yourself.

User request: $ARGUMENTS
