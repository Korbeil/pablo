---
description: Use when the user wants to start or create a Pablo task, or asks to "work on this issue" / "start a task" through PABLO.
---

# Creating a Pablo task

Prerequisite: the project must be configured. If `pablo task:start --project <name>` fails
with "not a known project", first run:

```
pablo project:new <name> --type <work|open-source|personal> --tracker <github|jira|linear>
```

(or ask the user with which type/tracker; see `docs/configuration.md`).
After registering a project, re-run `./bin/install.sh` so the provider agent templates regenerate.

Create a task:

- From an issue (type auto-detected from the URL):

  ```
  pablo task:start https://acme.atlassian.net/browse/XXX-123
  ```

- From a plain prompt (needs an explicit project):

  ```
  pablo task:start --project <name> "short task prompt"
  ```

That is all: the CLI creates the worktree and branch, tracks state under
`~/.pablo/`, and auto-launches the task-analyst agent. Never edit code,
state files or run `/pablo-commit-and-pr` yourself to create/advance a task —
the user drives the rest.

Verifying / managing:

- `pablo task:list` — active tasks and states
- `pablo task:close <branch>` — close (`--project <name>` if ambiguous)

Full reference: `docs/agents-commands.md` and `docs/state-machine.md`.
