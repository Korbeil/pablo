# AGENTS.md

Guidance for agents working in this repository.

## What this is

PABLO is an AI task orchestrator: a small Python engine (Poetry package
`pablo`) plus OpenCode agents/commands, and a background scheduler
(systemd on Linux, launchd on macOS). Not a standalone product.

**`README.md` + `docs/*.md` are the source of truth for behavior** — read
the relevant doc before non-trivial changes. `docs/specification.md` is
the original prompt only, superseded where it conflicts.

**Philosophy:** PABLO agents are read-only and analysis-first. PABLO never
writes/modifies application code, never resolves merge conflicts, never
mutates issue trackers (Jira/GitHub Issues/Linear stay read-only). It does
perform git/PR-metadata ops (branches, worktrees, rebase,
force-with-lease push, draft PR toggling, worktree deletion), but only per
README's explicit rules.

## Commands

```bash
poetry install && poetry run pytest         # setup + full test suite
poetry run pytest tests/test_states.py      # one file
poetry run pytest tests/test_states.py::test_name  # one test
pablo --help                                # engine subcommands
./bin/install.sh / ./bin/uninstall.sh       # install/remove symlinks + scheduler
journalctl --user -u pablo-dispatch.service -f  # dispatcher logs (Linux)
```

No lint/format config exists — match surrounding style.

## Architecture

`src/pablo/`: `cli.py` (entry point, one `cmd_*` per subcommand) ·
`config.py` (loads `projects/*.yaml`, merges with `projects/default.yaml`)
· `model.py` (`Task`/`Issue` dataclasses, state constants) · `store.py`
(state store + per-task flock) · `states.py` (state machine — see below) ·
`naming.py` (branch naming) · `gitrepo.py` (worktrees, lease-safe sync) ·
`sync.py` / `poller.py` (per-project background jobs) · `ghpr.py` (GitHub
PR/CI/review plumbing) · `agents.py` (launches agents via Orca) ·
`confluence.py` (Confluence page fetch via the `acli` CLI, used by
`pablo docs` / `/pablo-docs` and by interactive agents) · `listing.py`
(table rendering) · `dispatch.py` (cron entry point) · `doctor.py`
(preflight checks) · `providers/` (`github.py`/`jira.py`/`linear.py`
behind a common interface).

**New state**: add to `ALL_STATES` in `model.py` + a `StateDef` in
`STATES` in `states.py`. Always transition via `enter_state()`, never
mutate `task.state` directly.

**New provider**: implement the `providers/*.py` interface, register in
`providers/__init__.py`.

**Two layers**: interactive (OpenCode agents/commands the user invokes
directly — commands wrap `pablo` CLI calls; agents are read-only,
auto-launched by `states.py` on certain state transitions) and background
(`pablo dispatch`, run every 5 min by systemd/launchd; global flock, per
project × {sync, poll} checks a stamp in `~/.pablo/stamps/` against that
project's own interval; one project's failure never blocks others).

**State machine**: one task = one worktree (`/pablo-start`). Rough flow:
`in-progress` → `waiting` (pausable, forbidden from
`request-changes`/`testing-failed`) → `draft` → `ci-red` /
`ready-to-review` → `waiting-review` → `needs-testing` /
`request-changes` → maybe `testing-failed`. `/pablo-commit-and-pr` is the
only way back into `draft` (see `COMMIT_ALLOWED_FROM`). Full transition
rules (review precedence, failure-signal baselines) live in
`docs/state-machine.md` — several encode deliberately non-obvious edge
cases, read before touching transition logic.

**Task storage**: always under `~/.pablo/`, never in a project
repo/worktree — `state/<project>/<branch>.json` (+ `.lock`),
`stamps/<project>.{sync,poll}`, `agents/` (pidfiles+logs),
`worktrees/`. Atomic writes, UTC ISO-8601 timestamps. The `Task` record
also holds a poller-written **display cache**
(`cached_tracker_status`/`cached_pr_state`/`cached_agent_count`/
`cached_agent_activity`/`cached_at`) so `pablo tasks` renders instantly;
`None` means never-polled and `listing.py` falls back to a live fetch.
`--live` forces a live fetch, `--refresh` forces the poller first.

**Agent execution**: via the Orca CLI (`orca terminal create` /
`orca terminal wait` / `orca worktree ps`), same path as the user's other
Orca agents. Falls back to headless `opencode run --dir <worktree>` for
repos not registered in Orca.

## Testing

`tests/conftest.py` autouse-stubs `agents.launch`/`spawn_watcher`/
`active_sessions` for every test **except** `test_agents.py` — this stops
a forgotten stub from starting a real `opencode run` (a real LLM call)
during `pytest`. Never remove it or widen the exemption.

## Config (`projects/*.yaml`)

`default.yaml` holds PABLO-wide defaults (skipped by the loader). Projects
override per-key via `config.DEFAULT_ELIGIBLE`: `sync.strategy`,
`sync.auto_apply`, `sync.interval_minutes`,
`state_polling.interval_minutes`, `review.bot_whitelist`,
`ci.ignore_checks`. Types: `work`/`open-source`/`personal`. Providers:
`github`/`jira`/`linear`.

## Naming note

The command is `/pablo-commit-and-pr`, not `/commit-and-pr` — the spec's
name — because the user's own pre-existing `/commit-and-pr` command must
keep working untouched. Don't rename it to match the spec.
