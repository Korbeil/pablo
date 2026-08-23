# AGENTS.md

Guidance for agents working in this repository.

## What this is

PABLO is an AI task orchestrator: a small PHP engine (a standalone Symfony
Console application, Composer package `pablo`) plus OpenCode agents/commands,
and a background scheduler (systemd on Linux, launchd on macOS). Not a
standalone product.

**`README.md` + `docs/*.md` are the source of truth for behavior** — read
the relevant doc before non-trivial changes.

**Philosophy:** PABLO agents are read-only and analysis-first. PABLO never
writes/modifies application code, never resolves merge conflicts
deterministically — sync aborts cleanly and hands off to the auto-launched
`rebase-conflict-resolver` agent, the single deliberate exception to the
read-only rule — and never mutates issue trackers (Jira/GitHub Issues/
Linear stay read-only). It does
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
castor qa:cs:check                          # same as cs-fixer but --dry-run (CI)
castor qa:twig-cs-fixer                     # twig-cs-fixer (app/templates)
(cd app && composer test)                   # same as castor qa:test
(cd app && composer exec phpunit tests/StateMachineTest.php)  # one file
pablo --help                                # engine subcommands
pablo web                                   # read-only dashboard on 127.0.0.1:8321
php app/bin/console cache:clear             # framework CLI: cache:clear, lint:twig, debug:router,
                                            # importmap:*, ux:icons:import
./bin/install.sh / ./bin/uninstall.sh       # install/remove shim + scheduler
journalctl --user -u pablo-dispatch.service -f  # dispatcher logs (Linux)
```

Code style is enforced by php-cs-fixer (`app/.php-cs-fixer.php`) and PHPStan
at **level 8** (`app/phpstan.neon`), both over `src`, `tests`, `config` and
`public` — raise it further by fixing reported errors, never by ignoring or
baseline-suppressing them. Run both via `castor qa:*` before non-trivial
changes. `qa:cs-fixer` uses `--path-mode intersection`, so a new top-level
directory must be added to **both** `castor.php` and `.php-cs-fixer.php`.

## Architecture

The application lives under `app/`; namespaces mirror folders
(`Pablo\ => src/`, i.e. `app/src`). Paths below are relative to `app/`
unless noted.

**One kernel, three entry points.** `src/App/Kernel.php` is a
FrameworkBundle `MicroKernelTrait` kernel (project dir = `app/`, container
cached in `var/cache/<env>`) serving `bin/pablo` (the user-facing CLI),
`bin/console` (framework maintenance) and `public/index.php` (the
dashboard). `PABLO_ENV` defaults to `dev` — **not** `prod`, where the
container cache is never checked for freshness and a `git pull` would leave
a stale container forever. `MicroKernelTrait`'s default
`configureContainer`/`configureRoutes` are used as-is; config lives in
`config/{bundles,services,routes}.php` + `config/packages/`.

⚠️ **Never resolve a `PABLO_*` env var (or `HOME`) at container compile
time** — the container is cached, so a compile-time value freezes on
whichever process warmed the cache. `Store` and `Agents` resolve their own
defaults in their constructors for exactly this reason; don't turn them
back into container parameters.

`src/App/ConsoleApplication.php` consumes the **`pablo.command`** tag (not
`console.command`, which every bundle now contributes to) so `pablo --help`
lists PABLO subcommands only · `src/Command/*.php` (one Symfony Console
command per subcommand, grouped by topic under `Task/` `Sync/` `Report/`
`System/` `Internal/`) ·
`src/Config/` (project YAML loading + `ProjectConfig`) · `src/Domain/`
(`Task`/`Issue` records, the `State` and `Agent` enums, `Time`) ·
`src/StateMachine/` (the data-driven state machine) · `src/Store/` (state
store + per-task flock) · `src/Poller/` (state polling) ·
`src/Provider/` (all external integrations): `Gh/` (`gh` PR/CI),
`Git/` (worktrees, lease-safe sync), `Confluence/` (`acli`),
`Tracker/` (`ProviderInterface` + `Github`/`Jira`/`Linear`) ·
`src/Agents/` (agent launchers: Orca + headless `Agents`, optional
`OpenChamber`, `AbstractAgentLauncher` base, `AgentLauncher` factory
selected via `agent_backend`/`PABLO_AGENT_BACKEND`) · `src/Listing/` (terminal tables) ·
`src/Analytics/` (append-only JSONL analytics under `~/.pablo/analytics/`:
task lifetimes, state transitions, agent runs with token/cost usage
harvested from the local OpenCode CLI; best-effort, never throws into task
flows, never persists prompt content — see `docs/analytics.md`) ·
`src/Doctor/` (preflight checks) · `src/Dispatch/` (cron entry point) ·
`src/Support/` (`PabloError`, `Proc`, `Naming`, `RepoSlug`).

**Dashboard** (`pablo web`, see `docs/dashboard.md`): `src/Dashboard/`
(`Dashboard` — the cache-only data service, `TaskView`, `PollSchedule`,
`RebaseLogView`) · `src/Controller/` (the single `GET /`) ·
`src/Twig/Components/` (UX Twig/Live components) · `templates/` ·
`assets/`. Two hard rules: the page is **strictly read-only**, and a page
render must never call `gh`/`orca`/a tracker CLI — it reads the poller's
`DisplayCache`. Only the Slack modal fetches live, on demand.
`Domain/PrBadge` and `Domain/AgentActivity` are the shared value objects
`Listing` renders terminal cells over, so the two surfaces cannot drift;
`tests/Listing/ListingTest.php` is what proves terminal output unchanged.

**New state**: add a case to `State` in `src/Domain/State.php` + a
`StateDef` in the `states()` table in `StateMachine.php`. Always transition
via `StateMachine::enterState()`, never mutate `task.state` directly.

**New provider**: implement `ProviderInterface`, register in
`ProviderRegistry`.

**Two layers**: interactive (OpenCode agents/commands the user invokes
directly — commands wrap `pablo` CLI calls; agents are read-only,
auto-launched on certain state transitions) and background
(`pablo system:dispatch`, run every 5 min by systemd/launchd; global flock, per
project × {sync, poll} checks a stamp in `~/.pablo/stamps/` against that
project's own interval; one project's failure never blocks others).

**State machine**: one task = one worktree (`pablo task:start`). Rough flow:
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
in `src/Domain/`) so `pablo task:list` renders instantly;
`null` means never-polled and `Listing` falls back to a live fetch.
`--live` forces a live fetch, `--refresh` forces the poller first.

**Agent execution**: via the Orca CLI (`orca terminal create` /
`orca terminal wait` / `orca worktree ps`), same path as the user's other
Orca agents. Falls back to headless `opencode run --dir <worktree>` for
repos not registered in Orca. An optional `OpenChamber` backend
(`agent_backend=openchamber` in `~/.pablo/config.yaml` or
`PABLO_AGENT_BACKEND`) replaces Orca with the directory-based `openchamber`
CLI — no repo registration, adoption wait, or display-name step.

## Testing

Tests live under `app/tests/` mirroring `app/src/` (e.g. `app/src/Store/Store.php`
is covered by `app/tests/Store/StoreTest.php`) so a test is found next to its
subject. `tests/FakeAgents.php` is injected through `AgentLauncherInterface`
wherever a test could otherwise reach a real `Agents` — this stops a forgotten
real `Agents` from starting a real `opencode run` (a real LLM call) during
PHPUnit. Never remove it or widen the exemption. The engine is DI-based: every
former static class (`GhPr`, `GitRepo`, `Proc`, `Config`, `StateMachine`, …) is
a constructor-injected service behind an interface where tests need fakes
(`GhPrInterface`, `GitRepoInterface`, `ProcessRunnerInterface`,
`ProviderRegistryInterface`, `AnalyticsInterface`). Test doubles live beside
`tests/FakeAgents.php` (`FakeGit`, `FakeGhPr`, `FakeProcessRunner`,
`StubProviders`, `FakeAnalytics`) and
`tests/Command/CommandTestBed.php` is the abstract bed for command tests,
wiring that fake graph in `setUp`. `tests/EngineGraph.php` builds the same
graph for engine-layer tests (poller, listing, dispatch).
`tests/PortabilityTest.php` is a tripwire scanning `src/**/*.php` for
`systemctl`/`journalctl`/`/etc/`/`/proc/`/`/opt/` to keep the engine
Linux+macOS portable.

Any test that **boots the kernel** must `use Pablo\Tests\RestoresErrorHandlers`
and bracket the boot with `snapshotErrorHandlers()`/`restoreErrorHandlers()`:
`FrameworkBundle::boot()` installs Symfony's `ErrorHandler` globally and
nothing removes it, which PHPUnit reports as a risky test. It registers with
`$replace=false`, so an unconditional `restore_error_handler()` would pop
PHPUnit's own handlers on the second boot — hence snapshot-then-compare.

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
