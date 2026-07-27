# P.A.B.L.O.
<i>Personal Assistant for Boring Logic & Operations</i>

PABLO is an AI orchestrator for the projects the user works on. It is not
a standalone CLI product: it plugs into an existing OpenCode/Orca agent
setup as a set of **OpenCode agents, commands, and skills**, backed by a
small Python engine and an unattended background layer.

PABLO's philosophy: **agents are read-only and analysis-first**. PABLO
never writes or modifies code, never resolves conflicts, and never mutates
issue trackers (Jira / GitHub Issues / Linear stay strictly read-only). It
*does* perform git and PR-metadata operations — creating branches and
worktrees, rebasing, force-pushing (with lease only), creating draft PRs,
toggling PRs draft/ready, deleting worktrees — but only per the explicit
rules documented below, never on its own judgement.

## Quick start

Prerequisites: Python 3.12 + Poetry, `gh` (+ `gh auth login`), the Orca
and opencode apps, nvm with a Node matching `.nvmrc`. Full details and
platform-specific steps: [docs/installation.md](docs/installation.md).

```bash
./bin/install.sh    # poetry install, symlinks agents/commands, starts the
                     # background scheduler, finishes with `pablo doctor`
pablo doctor        # verify everything's installed and authenticated
```

Add a project by dropping a YAML file in `projects/` (copy an existing
one, e.g. `projects/acme-pim.yaml`, or see the full schema in
[docs/configuration.md](docs/configuration.md)).

Then, day to day, from an OpenCode session:

```
/pablo-start https://acme.atlassian.net/browse/XXX-123          # start a task from an issue
/pablo-start --project wallet-kit "fix callback verification"   # ...or from a prompt
/pablo-tasks             # see all active tasks and their state
/pablo-issues            # see issues assigned to you, per project
/pablo-commit-and-pr     # commit, push, open a draft PR
/pablo-waiting           # pause/resume a task
/pablo-close             # abandon/clean up a task (escape hatch)
```

PABLO takes it from there in the background — running review/CI/testing
agents and advancing the task's state automatically. See
[docs/state-machine.md](docs/state-machine.md) for the full flow and
[docs/agents-commands.md](docs/agents-commands.md) for every command.

## Example workflow

A task's life from issue to merge, alternating what you do and what
PABLO does in the background:

1. `/pablo-start https://acme.atlassian.net/browse/XXX-123` — the task
   starts: worktree and branch created, a **task-analyst** agent launches.
2. `/pablo-tasks` — check that it's running.
3. The task-analyst agent finishes. Open it in Orca and read its plan.
4. Chat with the agent in that session to fine-tune the plan.
5. `/pablo-commit-and-pr` — commits, pushes, opens the PR as **draft**.
6. CI runs... and turns red.
7. PABLO notices and launches a **ci-analyst** agent to dig into the
   failure.
8. Read its output, chat with it to work out the fix, then
   `/pablo-commit-and-pr` again — the PR goes back to `draft` while CI
   reruns.
9. CI turns green 🎉 — PABLO takes the PR out of draft
   (`ready-to-review`).
10. A coworker reviews your changes and approves.
11. PABLO detects the approval and moves the task to `needs-testing` —
    waiting on QA.
12. QA tests it and signs off.
13. The PR is merged. PABLO detects the merge, deletes the task's state,
    and removes the worktree.

## Layout

```
pablo/
├── README.md                  ← you are here (short overview)
├── docs/                      ← one file per subject, source of truth for details
├── docs/specification.md      ← original build prompt, not the reference
├── pyproject.toml             ← Poetry package `pablo` (Python 3.12 + PyYAML)
├── projects/                  ← one YAML per managed project + default.yaml
├── src/pablo/                 ← the engine (see "Engine modules")
├── tests/                     ← pytest suite
├── bin/install.sh             ← symlinks agents/commands/units, enables timer
├── bin/uninstall.sh           ← reverse of install.sh (keeps ~/.pablo data)
├── systemd/                   ← pablo-dispatch.service + .timer (user units)
└── opencode/
    ├── agents/                ← task-analyst.md, task-feedback.md, ...
    └── commands/               ← the /pablo-* command set
```

Everything PABLO creates lives inside this repository. The originals in
`~/.config/opencode/{agents,commands}/` are never modified — `install.sh`
only creates **new symlinks** pointing into this repo (and refuses to
overwrite anything that isn't already such a symlink). Runtime data lives
in `~/.pablo/` (see [docs/state-machine.md](docs/state-machine.md#task-state-storage)).

### Engine modules (`src/pablo/`)

| module | responsibility |
|---|---|
| `cli.py` | the `pablo` entry point; every command/timer wraps a subcommand here |
| `config.py` | project YAML loading + per-key defaults merge |
| `model.py` | `Task` / `Issue` records, state-name constants |
| `store.py` | central state store + per-task lock |
| `states.py` | the data-driven state machine (single on-enter handler) |
| `naming.py` | branch naming convention + `-2`/`-3` dedupe |
| `gitrepo.py` | git plumbing: worktrees, lease-safe sync |
| `sync.py` | the per-project worktree-sync job |
| `poller.py` | the per-project state-polling job + merge auto-close |
| `ghpr.py` | GitHub PR plumbing: CI evaluation, review evaluation, draft/ready |
| `agents.py` | launching OpenCode agents via Orca, activity queries |
| `listing.py` | the `pablo issues` / `pablo tasks` terminal tables |
| `dispatch.py` | the cron dispatcher fired by the systemd timer |
| `doctor.py` | CLI preflight checks (`pablo doctor`) |
| `providers/` | one module per issue tracker behind a small interface |

## Documentation

Each subject has its own file under `docs/` — read the relevant one
before making non-trivial changes in that area:

- [docs/installation.md](docs/installation.md) — install script, per-platform
  dispatcher, macOS smoke checklist, Orca visibility
- [docs/agents-commands.md](docs/agents-commands.md) — the agents, the
  `/pablo-*` commands, skills
- [docs/state-machine.md](docs/state-machine.md) — starting a task, every
  state and transition, task storage format
- [docs/background-layer.md](docs/background-layer.md) — the two layers,
  worktree sync, closing a task, agent execution via Orca
- [docs/providers.md](docs/providers.md) — GitHub/Jira/Linear access,
  Jira-via-MCP details, `pablo doctor` preflight
- [docs/configuration.md](docs/configuration.md) — project YAML schema,
  branch naming convention
- [docs/listings.md](docs/listings.md) — `/pablo-issues` and
  `/pablo-tasks` output

## Development

```bash
poetry install && poetry run pytest              # full test suite
pablo --help                                     # engine subcommands
journalctl --user -u pablo-dispatch.service -f   # background layer logs
```
