# Castor migration plan

> **Update:** the entry layer is now a **standalone Symfony Console
> application** (`bin/pablo`) with a compiled DI container, not Castor tasks —
> per https://github.com/symfony/symfony/pull/63880. The domain modules
> (`src/*.php`, `tests/*.php`) stay the same and are Castor-agnostic; the
> Castor-specific surface below (castor.php, `#[AsTask]`, the `--castor-file`
> shim, and Gotcha 1) no longer apply. `src/Command/*.php` hold the Symfony
> `Command` classes, `src/Support/Proc.php` is the shell-out helper,
> `src/App/Kernel.php` + `config/services.php` wire the container, and
> `bin/install.sh` writes a `~/.local/bin/pablo` shim that execs `php bin/pablo`.
> State honours live in the `Pablo\Domain\State` / `Agent` enums; value objects
> replace ad-hoc arrays (`AgentLaunch`, `DisplayCache`).

Migrating the PABLO engine from Python (Poetry package `pablo`) to Castor
tasks (PHP). Working branch: `feat/castor-migration`.

## Context

PABLO is currently a Poetry package (`src/pablo/`, ~3,050 lines across 16
modules) exposing a `pablo` CLI, plus a pytest suite (5,305 lines, 20 test
files, 255 test functions — a 1.7:1 test-to-code ratio), driven by OpenCode
agents/commands and a systemd/launchd dispatcher. The goal is to remove the
Python entirely and rebuild the engine as Castor tasks, keeping bash where
bash makes sense.

**This is a good candidate.** The assessment that motivates the plan:

- The only third-party Python dependency is **PyYAML** (`pyproject.toml`).
  Everything else is stdlib: `pathlib`, `json`, `datetime`, `dataclasses`,
  `os`, `re`, `subprocess`, `fcntl`, `unicodedata`, `argparse`. Each has a
  direct PHP equivalent.
- The majority of the engine is **shell-out orchestration**, not algorithms:
  `ghpr.py` (309 l) is `gh` + `json_decode`, `gitrepo.py` is git plumbing,
  `agents.py` shells to `orca`/`opencode`, `confluence.py` shells to `acli`.
  Castor's `run()`/`capture()` fit this better than `subprocess` does.
- **No async, no threading, no signals.** The only concurrency primitives are
  `fcntl.flock` (3 modules, 3 distinct protocols) and detached
  `subprocess.Popen(start_new_session=True)` (4 sites in `agents.py`).
- The two genuinely awkward past dependencies are **already gone**: the MCP
  stdio client for the Jira bridge was deleted in `722b416` in favour of plain
  `acli` shell-outs. No Node bridge left to port (only a vestigial `.nvmrc`).
- The public contract is **already a CLI boundary**. All 13
  `opencode/commands/*.md` and 5 `opencode/agents/*.md` are pure prompt text
  that only ever call `pablo <subcommand>`; `systemd/pablo-dispatch.service`
  and the launchd plist call `~/.local/bin/pablo dispatch`. **None of these
  files change** so long as subcommand names and output formats hold.

Decisions taken: port the test suite to PHPUnit; big-bang on a feature branch;
ship as `composer install` + a shim symlink to `castor.php`.

## Environment findings (verified, not assumed)

Probed on this machine: PHP 8.4.22, Castor v1.7.0, Composer, all on PATH.

| Question | Answer |
|---|---|
| Does bare `castor` from another repo find PABLO? | **No** — finds only built-ins. The `--castor-file` shim is mandatory. |
| Does `--castor-file=<abs>` work from a foreign cwd? | **Yes**, and it *preserves* cwd as the foreign dir. |
| What cwd do `run()`/`capture()` inherit? | The **invocation** cwd, not the castor.php dir — same as Python's `subprocess`. |
| Are the phar's Symfony deps scoped/prefixed? | **No.** `Symfony\Component\{Yaml,Process,Console,Filesystem}` are all directly usable. |
| `posix_kill`, `mb_strwidth` available? | **Yes** (needed for `_pid_alive` and column widths). |
| `ext-intl` (`Transliterator`, `Normalizer`) available? | **No — missing.** See "Unicode" below. |

Preserving cwd matters: `task current`, `precommit-check` and `close` all
resolve the owning task from cwd via `git rev-parse --show-toplevel`.

## Target layout

```
pablo/
├── castor.php              ← entrypoint; imports src/, defines nothing itself
├── composer.json           ← phpunit + castor/castor (dev, for IDE + tests)
├── src/                    ← PHP namespace Pablo\  (replaces src/pablo/)
│   ├── Command/            ← one file per CLI subcommand, #[AsTask] functions
│   └── ...                 ← domain classes (see module map below)
├── tests/                  ← PHPUnit (replaces pytest)
├── bin/install.sh          ← stays bash, Python steps swapped for composer
├── systemd/ launchd/       ← UNCHANGED
├── opencode/               ← UNCHANGED
├── projects/               ← UNCHANGED (YAML parsed by symfony/yaml)
└── docs/                   ← updated prose only
```

Castor discovers tasks from **functions**, not methods. So: thin `#[AsTask]`
functions in `src/Command/` that parse input and delegate to plain PHP classes
holding the logic. This keeps the domain unit-testable without going through
Castor.

## Module map (Python → PHP)

Straight ports, low risk — thin wrappers over external CLIs:

| Python | PHP | Notes |
|---|---|---|
| `ghpr.py` (309 l) | `src/GhPr.php` | `gh` via `capture()` + `json_decode`. Keep `evaluateCi`/`evaluateReviews` as pure functions over decoded arrays — they are the testable core. |
| `gitrepo.py` (193 l) | `src/GitRepo.php` | worktrees, lease-safe sync |
| `confluence.py` (105 l) | `src/Confluence.php` | `acli` shell-out |
| `doctor.py` (126 l) | `src/Doctor.php` | preflight probes; `shutil.which` → `ExecutableFinder` |
| `providers/{github,jira,linear}.py` | `src/Provider/*.php` | `interface ProviderInterface` replaces the duck-typed module interface; register in a `ProviderRegistry` mirroring `providers/__init__.py` |

Real logic — these carry the behavioural risk:

| Python | PHP | Notes |
|---|---|---|
| `states.py` (314 l) | `src/StateMachine.php` | The `STATES` table + single `enter_state()` handler. `@dataclass StateDef` → readonly class; on-enter handlers → first-class callables. Preserve `COMMIT_ALLOWED_FROM`, `WAITING_FORBIDDEN_FROM`, `AGENT_LAUNCH_STATES` verbatim. **Read `docs/state-machine.md` first** — several transitions encode deliberate edge cases. |
| `poller.py` (341 l) | `src/Poller.php` | Review precedence + failure-signal baseline logic. Highest bug risk in the repo. |
| `listing.py` (505 l) | `src/Listing.php` | Hand-rolled tables. See Gotchas. |
| `model.py` (171 l) | `src/Task.php`, `src/Issue.php` | Explicit `toArray()`/`fromArray()`. Includes the poller display cache (`cachedTrackerStatus`/`cachedPrState`/`cachedAgentCount`/`cachedAgentActivity`/`cachedAt`), where `null` means never-polled. |
| `config.py` (182 l) | `src/Config.php` | `yaml.safe_load` → `Yaml::parseFile`. Preserve the `DEFAULT_ELIGIBLE` per-key merge against `projects/default.yaml` and the rule that `default.yaml` is skipped when scanning projects. |
| `store.py` (127 l) | `src/Store.php` | See Gotchas (flock). |
| `agents.py` (465 l) | `src/Agents.php` | See Gotchas (detached spawn, self-reinvocation). |
| `naming.py` (33 l) | `src/Naming.php` | Branch naming + `-2`/`-3` dedupe. See Unicode. |
| `sync.py` (200 l) | `src/Sync.php` | per-project sync job |
| `dispatch.py` (148 l) | `src/Dispatch.php` | global flock + per-project stamp/interval check |
| `cli.py` (737 l) | `src/Command/*.php` | Dissolved: argparse wiring becomes `#[AsTask]` attributes with typed params. |

## CLI surface to preserve

Externally depended on (by `opencode/commands/*.md` and the scheduler units):

`start` · `sync` · `issues` · `tasks` · `projects` · `doctor` · `state` ·
`waiting` · `skip-ci` · `retrigger-ci` · `close` · `precommit-check` ·
`task current` · `docs` · `dispatch`

Not yet wrapped by a command, but still part of the CLI: `poll`, `slack`,
`rebase-log`, `relaunch`, plus the internals `internal-launch-agent`,
`internal-run-startup-script`, `watch-agent`.

Declare each with `#[AsTask(name: '<name>', namespace: '')]` — the empty
namespace is required, or Castor derives one from the file path and you get
`pablo command:start`. Verified working. `pablo task current --json` is called
as that exact string by `pablo-close.md`, so match it however renders identically.

Flags to keep identical: `--json` (`precommit-check`, `task current`),
`--project`, `--yes` (`close`), `--live` / `--refresh` (`tasks`).

## Gotchas

**1. Castor file discovery.** `pablo` is invoked from *other repos'* worktrees.
Castor resolves `castor.php` by walking up from cwd, so a bare `castor` would
find nothing — or worse, a downstream project's own `castor.php`. The
`~/.local/bin/pablo` shim **must** pass `--castor-file=<REPO_DIR>/castor.php`.
Verified working.

**2. Self-reinvocation.** `agents.py:456` spawns watchers via
`sys.executable -m pablo.cli watch-agent …`. Replace with the absolute shim
path resolved once, not `$_SERVER['argv'][0]`, which is unreliable under Castor.

**3. Detached spawns.** 4 `Popen(start_new_session=True, std*=DEVNULL)` sites.
PHP has no `start_new_session`; use
`exec('setsid ' . $cmd . ' >/dev/null 2>&1 & echo $!')` to both detach and
capture the PID — needed, because `agents.py` writes pidfiles to
`~/.pablo/agents/<pid>.json` and `_pid_alive()` reaps them. Do **not** use
Castor's `run()`, which waits. Re-verify systemd `KillMode=process` after the swap.

**4. flock — three protocols, don't collapse them into one helper.**
- `store.py::task_lock` — non-blocking `LOCK_EX|LOCK_NB` spin, 30 s deadline,
  and it **writes holder metadata into the lock file**
  (`{"pid","argv","acquired_at"}`, `store.py:118`) after `ftruncate` so a
  timeout can name the blocker. `sync.py`/`poller.py` take this same lock with
  a **2 s** timeout and treat a timeout as "skip this task" — `sync.py`
  currently detects that via a fragile `if "locked" in str(exc)` substring
  test. **Fix in the port**: a distinct `TaskLockedException`.
- `agents.py::_acquire_launch_lock` — **blocking** `LOCK_EX` (no `NB`) held
  across the whole `orca terminal create`, with explicit `LOCK_UN`. Lock file
  name is the worktree path with every non-alphanumeric char replaced by `_`.
- `dispatch.py::_dispatch_lock` — non-blocking, and the context manager
  **yields a bool** rather than raising (`false` = a dispatcher is already
  running → no-op). PHP has no `yield`-based CM; make it
  `tryAcquire(): ?LockHandle` returning null.

**5. `signal_via_status` duck typing.** `poller.py:137` probes
`getattr(provider, 'signal_via_status', False)` — an optional attribute only
`providers/jira.py` sets (acli exposes no changelog, so Jira relies on the
observed-transition fallback). `providers/__init__.py` declares `Provider` as a
`typing.Protocol` (structural). PHP interfaces are nominal, so this becomes a
required `supportsSignalViaStatus(): bool` on `ProviderInterface` — a small but
real API change to make deliberately, not by accident.

**6. Emoji is load-bearing control flow in `listing.py`.** The two-section split
(`💭 Waiting for feedback` vs `Other tasks`) is driven not only by
`_WAITING_FEEDBACK_STATES` but by a literal substring test `"💭" in row[4]`
against the *already-rendered* cell. Sorting relies on a stable sort (`usort`
is stable in PHP 8+, so that part is fine). Keep the emoji constants in one
place — commit `74031b0` retuned them recently.

**7. Unicode — ext-intl is missing, and that is fine.** Two spots:
- `listing.py:135` — `unicodedata.east_asian_width(ch) in ("W","F")` for
  emoji/CJK column alignment → `mb_strwidth($s, 'UTF-8')`, which already
  returns 2 for W/F. Verify against the legend in `docs/listings.md`.
- `naming.py:21` — `unicodedata.normalize("NFKD").encode("ascii","ignore")`.
  `Transliterator`/`Normalizer` need ext-intl, which is **not installed**.
  Use `iconv('UTF-8', 'ASCII//TRANSLIT')` instead. Measured against the Python
  reference:

  | prompt | Python slug | iconv slug |
  |---|---|---|
  | `Émit weird  ---   chars?!` | `emit-weird-chars` | `emit-weird-chars` ✅ |
  | `café crème` | `cafe-creme` | `cafe-creme` ✅ |
  | `naïve résumé` | `naive-resume` | `naive-resume` ✅ |
  | `日本語 test` | `test` | `test` ✅ |
  | `Straße größe` | `strae-groe` | `strasse-grosse` ⚠️ |

  The only divergence is `ß`, where NFKD-drop produces the mangled `strae-groe`
  and iconv produces the correct `strasse-grosse`. Output was verified
  **locale-insensitive** across `LC_ALL` = `C`, `C.UTF-8`, `POSIX`, and unset,
  so the dispatcher's minimal systemd environment cannot change it.
  Adjust `test_slug_branch_strips_symbols`'s German case when porting.

  Note this is lower-risk than it looks: `slug_branch` is called **only at task
  creation** (`cli.py:171`), never for lookup. Existing branch names live in the
  task JSON and are unaffected.

**8. Timestamps.** `model.py utcnow()` produces UTC ISO-8601. Match the exact
format (`DateTimeImmutable::format(DATE_ATOM)` vs Python's `isoformat()` differ
on the `+00:00`/`Z` suffix and microseconds). Existing `~/.pablo/state/*.json`
must stay readable — this is live data, not a fresh start.

**9. JSON.** `json.dumps(indent=2)` →
`json_encode($d, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)`.
Python does not escape `/`; PHP does by default. Keep the trailing newline and
the atomic `tmp` + `rename` write from `store.py:52`.

**10. Agent denylists.** The four read-only agents deny `"php*"` and
`"composer*"` in bash permissions. They allowlist `pablo …` separately, so the
shim keeps working — but confirm no command file ends up invoking `castor` or
`php` directly.

**11. Function tables.** Four registries store callables in dicts: `STATES`
(state → on-enter), `LAUNCH_SPECS` (lambdas returning `(callable, args)`),
`POLL_CHECKS` (state → ordered first-hit-wins check list), and
`DEFAULT_RUNNERS`/`JOB_INTERVALS`. PHP first-class callable syntax
(`$this->enterCiRed(...)`) handles these; resist turning them into a class
hierarchy — the flat table is the design (`AGENTS.md`: "adding a state touches
this table plus its handler, nothing else"). Port `states.py`'s module-level
`assert set(STATES) == set(ALL_STATES)` invariant as a test, since PHP has no
import-time assert.

**12. Opportunistic fix.** `sync.py::_find_recent_session` interpolates a
worktree path unescaped into a SQL string passed to
`opencode db "SELECT id FROM session WHERE directory = '<wt>' …"`. Escape it in
the port rather than translating the bug faithfully.

## Test suite → PHPUnit

Port all 20 files (255 test functions). Three rules carried over:

- **The autouse agent stub is non-negotiable.** `conftest.py` stubs
  `agents.launch` / `spawn_watcher` / `active_sessions` / `bulk_active_sessions`
  for every test except `test_agents.py`, so a forgotten stub can never start a
  real `opencode run` (a real LLM call) during the suite. Reproduce it as a
  PHPUnit base `TestCase` that injects a fake `AgentLauncherInterface`, with
  `AgentsTest` the sole exemption. Design `Agents` behind an interface **from
  the start** — Python monkeypatching has no PHP equivalent.
- `PABLO_STATE_DIR` (`store.py:28`) is how tests get a temp store; keep it.
  Likewise `PABLO_PROJECTS_DIR` for `config.py`.
- **`test_portability.py` is a tripwire**: it greps every `src/pablo/**/*.py`
  for `systemctl`, `journalctl`, `/etc/`, `/proc/`, `/opt/` and fails if found,
  keeping the engine Linux+macOS portable. Port it to scan `src/**/*.php`.

Since the suite stubs `run_cli`/`subprocess` and asserts on the **exact argv**
passed to `gh`/`acli`/`linear`/`orca`, it doubles as an executable spec of the
external CLI contracts. Port those assertions verbatim — cheapest guarantee
that the PHP engine talks to the same tools the same way.

Two files exercise real I/O rather than mocks and should stay that way:
`conftest.py::repos` builds real git repos (bare origin + two clones) so
`test_gitrepo.py`/`test_sync.py` test actual rebase/force-with-lease behaviour;
`test_store.py:83` spawns a real subprocess to prove flock contention across
processes (becomes a PHP subprocess).

Port order by value — the logic that shell-outs don't cover:
`test_listing.py` (30 tests), `test_poller.py` (27), `test_ghpr.py` (22),
`test_cli_state.py` (22), `test_states.py` (20), `test_config.py` (12).

There is **no CI** in this repo (no `.github/`), so the suite only ever runs
locally — `composer test` should be the documented entrypoint.

## install.sh changes (stays bash)

Only three Python touchpoints in `bin/install.sh`:

1. `poetry install --only main --quiet` → `composer install --no-dev --quiet`
2. `poetry env info --path` + symlink to `$VENV_PATH/bin/pablo` → write a
   2-line shim to `~/.local/bin/pablo` that execs
   `castor --castor-file="$REPO_DIR/castor.php" "$@"`
3. `resolve_path()` (`install.sh:19`) uses `python3 -c os.path.realpath` →
   `php -r 'echo realpath($argv[1]);'`, or drop it for a bash loop

Everything else — the "refuse to overwrite non-PABLO symlinks" guard, the
systemd/launchd branches, the closing `pablo doctor` — is untouched.
`bin/uninstall.sh` needs no change (it only removes symlinks).

## Files deleted at cutover

`src/pablo/` (entire), `tests/` (replaced), `pyproject.toml`, `poetry.lock`,
and the vestigial `.nvmrc` (leftover of the deleted MCP bridge; nothing else
uses Node). Sweep the stale `__pycache__/` dirs too — they still hold
`mcpclient.cpython-312.pyc` for a module that no longer exists.

`.gitignore`: drop `__pycache__/`, `.pytest_cache/`, `.venv/`, `*.pyc`; add
`/vendor/`, `.phpunit.result.cache`. Keep the `projects/*.yaml` +
`!projects/default.yaml` rules exactly as they are — the four `acme-*.yaml`
configs are gitignored per-machine files that must survive the migration.

Docs to update: `README.md` (prerequisites, "Engine modules" table, Development
section), `AGENTS.md` (commands, architecture, the conftest paragraph),
`docs/installation.md` (Poetry → Composer; also fix its dangling link to the
nonexistent `agent-running.md`).

## Order of work

1. **Scaffold + prove the shim** — `composer.json`, `castor.php`, `src/`, one
   trivial `#[AsTask]`, run from an unrelated worktree cwd. ✅ **Done** — see
   "Environment findings".
2. Leaf domain, no deps: `Task`/`Issue`, `Config`, `Naming`, `Store` (+ tests).
3. Shell-out wrappers: `GitRepo`, `GhPr`, `Provider/*`, `Confluence`, `Doctor`.
4. `Agents` behind an interface, incl. detached spawn + pidfiles.
5. `StateMachine`, then `Poller`, then `Sync`, then `Dispatch`.
6. `Listing` (alignment is easiest to eyeball once real tasks load).
7. `src/Command/*` — every subcommand.
8. `install.sh`, docs, deletions.

## Verification

Run in order; each is a real gate, not a smoke test.

1. `composer test` — full PHPUnit suite green.
2. **Shim from a foreign cwd**: `cd ~/Sites/acme/ecommerce && pablo --help`
   must list PABLO's subcommands (guards Gotcha 1).
3. `pablo doctor` — all preflight checks pass (gh/acli/orca auth).
4. `pablo projects` and `pablo issues` — YAML loading + provider reads.
5. **Read live state without writing**: `pablo tasks` against the existing
   `~/.pablo/state/` must render every current task with correct emoji column
   alignment, and `pablo tasks --live` must match. Back up `~/.pablo/` first.
6. `pablo task current --json` from inside a real task worktree — same JSON
   shape as the Python version (diff them before deleting `src/pablo/`).
7. `pablo dispatch` by hand — stamps update in `~/.pablo/stamps/` and the
   global flock holds (run two concurrently; the second must no-op).
8. Detached-agent check: trigger a launch, confirm a pidfile appears in
   `~/.pablo/agents/`, the process survives its parent exiting, and it shows in
   `orca worktree ps`.
9. Reinstall end-to-end: `./bin/uninstall.sh && ./bin/install.sh`, then
   `systemctl --user status pablo-dispatch.timer` and watch one real timer fire
   via `journalctl --user -u pablo-dispatch.service -f`.
10. Full loop on one throwaway task: `/pablo-start` → `/pablo-tasks` →
    `/pablo-commit-and-pr` → `/pablo-close`.
