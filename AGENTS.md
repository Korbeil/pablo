# AGENTS.md

Guidance for agents working in this repository.

## What this is

PABLO is an AI task orchestrator: a small PHP engine (a standalone Symfony
Console application, Composer package `pablo`) plus OpenCode agents/commands,
and a background scheduler (systemd on Linux, launchd on macOS). Not a
standalone product.

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

The application lives in `app/` (Composer package `pablo`). Tooling runs from
the repo root via the global **Castor** binary + remote `castor-php/php-qa`.

```bash
castor qa:test                              # PHPUnit suite (app/)
castor qa:phpstan                           # static analysis, level 8 (app/src)
castor qa:phpstan --generate-baseline       # regenerate phpstan-baseline.neon
castor qa:cs-fixer                          # php-cs-fixer (@Symfony + @Symfony:risky)
(cd app && composer test)                   # same as castor qa:test
(cd app && composer exec phpunit tests/StateMachineTest.php)  # one file
pablo --help                                # engine subcommands
./bin/install.sh / ./bin/uninstall.sh       # install/remove shim + scheduler
journalctl --user -u pablo-dispatch.service -f  # dispatcher logs (Linux)
```

Code style is enforced by php-cs-fixer (`app/.php-cs-fixer.php`) and PHPStan
at **level 8** (`app/phpstan.neon`) — raise it further by fixing
reported errors, never by ignoring or baseline-suppressing them. Run both via
`castor qa:*` before non-trivial changes.

## Architecture

The application lives under `app/`; namespaces mirror folders
(`Pablo\ => src/`, i.e. `app/src`). Paths below are relative to `app/`
unless noted. Entry: `bin/pablo` (boots
`App\Kernel`, a compiled Symfony DI container) · `src/App/Kernel.php` +
`config/services.php` (service wiring; commands are `console.command`
services) · `src/App/ConsoleApplication.php` (registers `src/Command/*.php`)
· `src/Command/*.php` (one Symfony Console command per subcommand, grouped
by topic under `Task/` `Sync/` `Report/` `System/` `Internal/`; commands are
DI services tagged `console.command`) ·
`src/Config/` (project YAML loading + `ProjectConfig`) · `src/Domain/`
(`Task`/`Issue` records, the `State` and `Agent` enums, `Time`) ·
`src/StateMachine/` (the data-driven state machine) · `src/Store/` (state
store + per-task flock) · `src/Poller/` (state polling) ·
`src/Provider/` (all external integrations): `Gh/` (`gh` PR/CI),
`Git/` (worktrees, lease-safe sync), `Confluence/` (`acli`),
`Tracker/` (`ProviderInterface` + `Github`/`Jira`/`Linear`) ·
`src/Agents/` (launches agents via Orca) · `src/Listing/` (tables) ·
`src/Doctor/` (preflight checks) · `src/Dispatch/` (cron entry point) ·
`src/Support/` (`PabloError`, `Proc`, `Naming`, `RepoSlug`).

**New state**: add a case to `State` in `src/Domain/State.php` + a
`StateDef` in the `states()` table in `StateMachine.php`. Always transition
via `StateMachine::enterState()`, never mutate `task.state` directly.

**New provider**: implement `ProviderInterface`, register in
`ProviderRegistry`.

**Two layers**: interactive (OpenCode agents/commands the user invokes
directly — commands wrap `pablo` CLI calls; agents are read-only,
auto-launched on certain state transitions) and background
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
also holds a poller-written **display cache** (`DisplayCache` value object
in `src/Domain/`) so `pablo tasks` renders instantly;
`null` means never-polled and `Listing` falls back to a live fetch.
`--live` forces a live fetch, `--refresh` forces the poller first.

**Agent execution**: via the Orca CLI (`orca terminal create` /
`orca terminal wait` / `orca worktree ps`), same path as the user's other
Orca agents. Falls back to headless `opencode run --dir <worktree>` for
repos not registered in Orca.

## Testing

Tests live under `app/tests/` mirroring `app/src/` (e.g. `app/src/Store/Store.php`
is covered by `app/tests/Store/StoreTest.php`) so a test is found next to its
subject. `tests/FakeAgents.php` is injected through `AgentLauncherInterface` (via the
shared base `TestCase`) for every test **except** the `Agents` tests — this
stops a forgotten real `Agents` from starting a real `opencode run` (a real
LLM call) during PHPUnit. Never remove it or widen the exemption.
`tests/PortabilityTest.php` is a tripwire scanning `src/**/*.php` for
`systemctl`/`journalctl`/`/etc/`/`/proc/`/`/opt/` to keep the engine
Linux+macOS portable.

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
