# AGENTS.md

Guidance for agents working in this repository.

## What this is

PABLO is an AI task orchestrator: a small PHP engine (a standalone Symfony
Console application, Composer package `pablo`) plus OpenCode agents/commands,
and a background scheduler (systemd on Linux, launchd on macOS). Not a
standalone product. Users install it as a **single static binary** (Box phar +
static-php-cli `micro.sfx`, no PHP needed — see "Standalone binary" below);
contributors run `php app/bin/pablo` from the checkout.

**The documentation website is the source of truth for behavior** — its
pages live in `site/content/*.html` (published at
https://getpablo.dev/docs/); read the relevant page before
non-trivial changes, and update it in the same change when behaviour
moves. There is no other documentation in the repo: `README.md` only
links to the website, and there is no `docs/` folder.

**Philosophy:** PABLO agents are read-only and analysis-first. PABLO never
writes/modifies application code, never resolves merge conflicts
deterministically — sync aborts cleanly and hands off to the auto-launched
`rebase-conflict-resolver` agent, the single deliberate exception to the
read-only rule — and never mutates issue trackers (Jira/GitHub Issues/
Linear stay read-only). It does
perform git/PR-metadata ops (branches, worktrees, rebase,
force-with-lease push, draft PR toggling, worktree deletion, checkout +
`reset --hard` of a *clean, not-ahead* primary checkout onto its remote
primary branch), but only per the explicit rules in `site/content/how-it-works.html`
("What PABLO may change").

**Git discipline:** agents never `git commit` or `git push` on their own
initiative — not even when executing a plan that was decided, and not
even as the final step of requested work. The only legitimate triggers
are an explicit user demand made in the session for that specific
commit/push, or the deliberate invocation of `/pablo-commit-and-pr`
(the sole sanctioned path for task commits). Deliver work as an
uncommitted working tree, say so, and let the user commit/push. The
`rebase-conflict-resolver` is the single deliberate exception: it may
only perform the git writes its own task mandates (replay + push per its
Push rule), never new commits of its own.

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
castor site:build                           # build the website into _site/ (fails on a broken page: link)
castor site:serve                           # build + preview it on http://127.0.0.1:8000
(cd app && composer test)                   # same as castor qa:test
(cd app && composer exec phpunit tests/StateMachineTest.php)  # one file
castor build:binary [--micro=<micro.sfx> --spc=<spc>]  # dist/pablo.phar (+ dist/pablo, the static binary)
castor release:create [--no-latest]         # release main's last commit (named after its SHA's first 7 chars)
                                            # via binary.yml (workflow_dispatch release=true)
castor release:list [--limit=10]            # last releases (gh release list)
php app/bin/pablo --help                    # engine subcommands, from the checkout
php app/bin/pablo web                       # read-only dashboard on 127.0.0.1:8321 (php -S, see Pablo\Web\PhpServer)
php app/bin/console cache:clear             # framework CLI: cache:clear, lint:twig, debug:router,
                                            # importmap:*, ux:icons:import
php app/bin/pablo system:install            # install/update for this user (shim from a checkout,
                                            # the binary itself when run from it); takes
                                            # --runtime/--backend/--without-web
php app/bin/pablo system:uninstall          # remove services, links, command, PATH block
pablo update [<release>] [--check]          # binary only (a named release may be older): system:update (src/Update/) — latest release via
                                            # curl, sha256-checked, run once (must report `pablo <release>`),
                                            # renamed over the binary under the dispatch lock, then the new
                                            # binary runs system:install (--without-web kept)
# site/installer = the interactive `curl -fsSL https://getpablo.dev/installer | bash`
# script (detect runtimes + ADEs, download pablo-<os>-<arch> from the latest
# GitHub Release, verify sha256sums.txt, install ~/.local/bin/pablo, then hand
# over to `pablo system:install`; migrates a clone install's php shim);
# tests: app/tests/Install/ (castor qa:test)
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
FrameworkBundle `MicroKernelTrait` kernel (project dir = `app/`) serving
`bin/pablo` (the user-facing CLI), `bin/console` (framework maintenance) and
the dashboard (`pablo web`: `php -S` on `public/index.php`, see
`src/Web/PhpServer.php`). From a
checkout the container is cached in `var/cache/<env>` and `PABLO_ENV`
defaults to `dev` — **not** `prod`, where the container cache is never
checked for freshness and a `git pull` would leave a stale container forever.
From the binary (`PabloPaths::isPhar()`), the phar is read-only: the
container goes to `~/.pablo/cache/container/<version>/<env>-<hash of the
binary path+mtime>`, logs to `~/.pablo/logs`, and `PABLO_ENV` defaults to
`prod` (safe: the dir is versioned). HOME is resolved in the Kernel at
runtime (`Kernel::defaultEnvironment()`, `getCacheDir()`). `MicroKernelTrait`'s default
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
flows, never persists prompt content — see `site/content/analytics.html`) ·
`src/Doctor/` (preflight checks) · `src/Dispatch/` (cron entry point) ·
`src/Support/` (`PabloError`, `Proc`, `Naming`, `RepoSlug`).

**Dashboard** (`pablo web`, see `site/content/dashboard.html`): `src/Dashboard/`
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
project × {sync, poll, main} checks a stamp in `~/.pablo/stamps/` against that
project's own interval; one project's failure never blocks others).

**State machine**: one task = one worktree (`pablo task:start`). Rough flow:
`in-progress` → `waiting` (pausable, forbidden from
`request-changes`/`testing-failed`) → `draft` → `ci-red` /
`ready-to-review` → `waiting-review` → `needs-testing` /
`request-changes` → maybe `testing-failed`. `/pablo-commit-and-pr` is the
only way back into `draft` (see `COMMIT_ALLOWED_FROM`). Full transition
rules (review precedence, failure-signal baselines) live in
`site/content/states.html` — several encode deliberately non-obvious edge
cases, read before touching transition logic.

**Task storage**: always under `~/.pablo/`, never in a project
repo/worktree — `state/<project>/<branch>.json` (+ `.lock`),
`stamps/<project>.{sync,poll}`, `agents/` (pidfiles+logs),
`worktrees/`, plus generated `runtime/` (rendered agents/commands/skills) and
`cache/container/<version>/` (the binary's compiled container). Atomic writes, UTC ISO-8601 timestamps. The `Task` record
also holds a poller-written **display cache** (`DisplayCache` value object
in `src/Domain/`) so `pablo task:list` renders instantly;
`null` means never-polled and `Listing` falls back to a live fetch.
`--live` forces a live fetch, `--refresh` forces the poller first.

**Bundled resources are read-only.** `PabloPaths::resourcesDir()` is the
repo root, or the inside of the phar (same layout). Never write next to
templates; never `glob()` a bundled dir (it returns nothing on `phar://`) —
use `Support\Files::list()`/`copy()`. Everything rendered goes under
`~/.pablo/runtime/` (`PABLO_RUNTIME_DIR`): `opencode/{agents,commands,skills}`
(commands/skills are extracted: a symlink cannot point into the binary),
`claude/{agents,commands}` + `agents.json`, `copilot/{agents,skills}`. The
links in `~/.config/opencode`, `~/.claude`, `~/.copilot` point there.

**Dashboard server** (`src/Web/PhpServer.php`): PHP's built-in server
(`php -S`, `PHP_CLI_SERVER_WORKERS=4`), 127.0.0.1 only (not configurable).
From a checkout: `PHP_BINARY -S … app/public/index.php`. From the binary: the
`micro` SAPI has no `php -S`, so the binary carries a static PHP CLI
(`bin/php` in the phar, added at build time by `castor build:binary
--php-cli` / the workflow); `pablo web` extracts it, a plain copy of the phar
(the bytes after `micro_get_sfxsize()`: a regular PHP cannot read the phar
inside the binary) and a router into
`~/.pablo/cache/container/<version>/web-<hash>/`, and `public/index.php`
serves the compiled assets from inside the phar. Never `pcntl_fork()` a
process that has the phar open: the children share its file handle and
concurrent reads corrupt each other (crc32 mismatch) — `php -S` forks its
workers before opening it.

**Install** (`src/Install/`, `system:install` / `system:uninstall`):
idempotent; never touches a pre-existing non-PABLO file in
`~/.config/opencode`, `~/.claude`, `~/.copilot` (a link is PABLO's when it
points into `~/.pablo/runtime` or into a PABLO checkout — the clone install's
links, taken over on migration); systemd units / launchd plists are bundled
and **written** (marker line, never symlinked), pointing at
`~/.local/bin/pablo`; the dashboard's port is `dashboard_port` in
`~/.pablo/config.yaml` (default 8321, read by `pablo web`, so never baked into
a unit; `system:install --port` writes it, and `site/installer` picks a free
one when another program holds 8321). `SystemdScheduler` is the one file `PortabilityTest`
lets name `systemctl` (built only on Linux by `SchedulerFactory`).

**Agent execution**: via the Orca CLI (`orca terminal create` /
`orca terminal wait` / `orca worktree ps`), same path as the user's other
Orca agents. Falls back to a headless run executed with the worktree
as the command's working directory for
repos not registered in Orca. *What* runs is the agent **runtime**
(`src/Agents/Runtime/`, `agent_runtime` / `PABLO_AGENT_RUNTIME`, resolved per
call by `AgentRuntimeResolver`): `opencode run` (default), `claude -p`
(Claude Code: agents passed with `--agents ~/.pablo/runtime/claude/agents.json`, and
also rendered as `pablo-<agent>.md` into `~/.pablo/runtime/claude/agents/`,
linked into `~/.claude/agents` for `/agents` and the `@` picker — tool-level
limits only; permissions translated from the same templates by `ClaudeCodeAgents`, every
shell deny doubled in its `rtk`-prefixed form) or `copilot -p` (GitHub
Copilot CLI: `pablo-<agent>.agent.md` files rendered by `CopilotAgents` into
`~/.pablo/runtime/copilot/` and linked into `~/.copilot/agents`, policy
repeated as `--available-tools`/`--allow-tool`/`--deny-tool` flags, session
handles recorded as `copilot:<uuid>`). OpenChamber + anything but OpenCode
is refused (factory, doctor, dispatch). An optional `OpenChamber` backend
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
`state_polling.interval_minutes`, `main_update.enabled`,
`main_update.interval_minutes`, `review.bot_whitelist`,
`ci.ignore_checks`. Types: `work`/`open-source`/`personal`. Providers:
`github`/`jira`/`linear`.

## Naming note

The command is `/pablo-commit-and-pr`, not `/commit-and-pr` — the spec's
name — because the user's own pre-existing `/commit-and-pr` command must
keep working untouched. Don't rename it to match the spec.

## Website & documentation

`site/` is the GitHub Pages website (deployed by `.github/workflows/pages.yml`,
which also builds it on pull requests). `site/index.html` + `style.css` +
`assets/` are the hand-written home page; each documentation page is an HTML
fragment `site/content/<slug>.html` (an `<h1>`, then a first `<p>` used as
its summary) listed in `site/content/nav.yaml`. `site/build/` (its own
Composer project, PHP 8.4 `Dom\HTMLDocument`) wraps pages in the layout,
adds heading anchors + the "On this page" column, highlights code, resolves
`page:<slug>#<id>` / `repo:<path>` / `asset:<path>` links — a broken one
fails the build — and writes `search-index.json` for the local search
(`site/search.js`). Example configs live in `examples/`
(`examples/config.yaml` is bundled in the binary and seeds
`~/.pablo/config.yaml` on `system:install`).

## Standalone binary

`app/box.json` (base-path = repo root, so the phar mirrors the repo layout)
packs `app/{src,config,templates,assets,public,vendor}` + `opencode/`,
`systemd/`, `launchd/`, `examples/config.yaml`; `@package_version@` (a Box
`replacements` entry set by castor and the workflow: the commit SHA's first 7
characters — never `git describe`/`git-version`, which yields
`<last release>-N-g<hash>` once a release tag exists) feeds
`ConsoleApplication::version()` (`dev` in a checkout).
`castor build:binary` builds from a staged copy in `dist/stage/` (no-dev
vendor, `asset-map:compile`) so the checkout's vendor and
`app/public/assets/` stay untouched, then `spc micro:combine`s it when given
`--micro` (and embeds `--php-cli` as `bin/php`). `.github/workflows/binary.yml` does the same per OS/arch
(linux-x86_64/aarch64, macos-arm64/x86_64) and smoke-tests (Linux: in a
`debian:stable-slim` container without PHP) on PRs and on demand. Releases
come from `castor release:create`, which dispatches it on `main` with
`release=true`: only after the four builds pass does its release job create
the GitHub Release — tag and title = the SHA's first 7 characters (exactly
what the binaries report; the job checks `pablo --version`), binaries + `sha256sums.txt`, flagged
latest unless `--no-latest` — so `releases/latest` never lacks binaries.
`pablo update` installs the latest-flagged release whenever its name differs
from the running version (no ordering between hashes). micro extensions:
`phar,ctype,iconv,mbstring,tokenizer,filter,posix,pcntl,dom,xml,zlib,openssl`
(keep `castor.php` and the workflow in step). No `opcache.enable_cli=1`: it
segfaults PHP 8.5 micro at startup.
