# Installation

```bash
./bin/install.sh                # dispatcher only
./bin/install.sh --with-web     # dispatcher + dashboard as a background service
```

Runs `composer install`, warms the DI container cache, writes the
`~/.local/bin/pablo` shim (which execs `php bin/pablo`), symlinks
`opencode/agents/*.md` and `opencode/commands/*.md` into
`~/.config/opencode/`, installs the background dispatcher for the
detected platform, and finishes with a `pablo system:doctor` run.
`pablo system:setup` proposes the same checks as a wizard, one step at a
time: when a required CLI is missing or unauthenticated it shows the exact
install/auth command to run and exits — re-run it after fixing to move on
to the next check (tracker CLIs `acli`/`linear` are only proposed once a
project actually uses them).
Pass `--with-web` to also install the dashboard as a long-running
background service (see [Dashboard](#dashboard) below).
`./bin/uninstall.sh` reverses it all (only removing symlinks/files PABLO
created; `~/.pablo` data is kept).

## Environment

| var | default | effect |
|---|---|---|
| `PABLO_ENV` | `dev` | Symfony environment for every entry point. `prod` never revalidates the container cache, so a `git pull` would keep serving a stale container and AssetMapper would 404 until `asset-map:compile` — only set it if you know you want that. |
| `PABLO_SECRET` | a fixed local string | signs Live Component payloads; irrelevant for a loopback-only dashboard |
| `PABLO_STATE_DIR`, `PABLO_PROJECTS_DIR`, `PABLO_STAMPS_DIR`, `PABLO_LOGS_DIR`, `PABLO_AGENTS_DIR` | under `~/.pablo` | relocate runtime data (all resolved per process, so they work even though the container is cached) |

The compiled container lives in `app/var/cache/<env>`. If a `sudo pablo`
ever leaves root-owned files there, every later run fails with "Unable to
write in the cache directory" — `install.sh` checks for this and tells you
to `sudo rm -rf app/var/cache`.

## Dashboard

```bash
pablo web                      # http://127.0.0.1:8321
pablo web --port 9000 --open
```

Serves the read-only dashboard from PHP's built-in web server — no Docker
and no `symfony` binary needed. Binds loopback only. Run it by hand with
`pablo web`, or keep it up as a background service by installing with
`./bin/install.sh --with-web` (stopped with `systemctl --user stop
pablo-web.service` / `launchctl bootout gui/$UID/com.pablo.web`). See
[dashboard.md](dashboard.md).

Per-platform scheduler and dashboard service (the engine itself is
OS-portable — guarded by `tests/PortabilityTest.php`):

| | Linux | macOS |
|---|---|---|
| scheduler | systemd user timer (`systemd/`, symlinked + enabled) | launchd LaunchAgent (`launchd/*.template` rendered to `~/Library/LaunchAgents/com.pablo.dispatch.plist`, `StartInterval` 300) |
| scheduler logs | `journalctl --user -u pablo-dispatch.service` | `~/.pablo/logs/dispatch.log` (+ `.err.log`) |
| scheduler status | `systemctl --user list-timers pablo-dispatch.timer` | `launchctl print gui/$UID/com.pablo.dispatch` |
| dashboard (`--with-web`) | `systemd/pablo-web.service`, enabled + auto-restart (`Restart=always`) | `launchd/com.pablo.web.plist.template` → `com.pablo.web.plist`, `KeepAlive` |
| dashboard logs | `journalctl --user -u pablo-web.service` | `~/.pablo/logs/web.log` (+ `.err.log`) |
| dashboard status | `systemctl --user status pablo-web.service` | `launchctl print gui/$UID/com.pablo.web` |

## macOS first-install smoke checklist

The launchd path was written portable-by-construction on Linux — walk
this once on the Mac:

1. Prerequisites: PHP 8.4.1+ + Composer, `gh` (+ `gh auth login`),
   the Atlassian CLI `acli` (+ `acli auth login` — covers both Jira and
   Confluence under one OAuth grant), the Orca and opencode apps.
2. `./bin/install.sh` → expect "com.pablo.dispatch loaded".
3. `pablo system:doctor` → all ✅.
4. `tail -f ~/.pablo/logs/dispatch.log` across one 5-minute tick → a
   clean dispatch run.
5. `pablo task:start …` + `pablo task:list` round trip on a real project.

## Orca visibility (verified 2026-07-26)

Orca's `--worktree path:` selector only resolves worktrees of repos
**registered in Orca** (`orca repo add <path>`; registered repos have
`externalWorktreeVisibility: show`). Register each managed project's repo
in Orca so PABLO's agent runs appear as Orca terminals; for unregistered
repos PABLO transparently falls back to headless `opencode run` (see
[background-layer.md](background-layer.md)).
