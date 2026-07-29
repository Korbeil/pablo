# Installation

```bash
./bin/install.sh
```

Runs `poetry install`, links the venv's `pablo` into `~/.local/bin/pablo`,
symlinks `opencode/agents/*.md` and `opencode/commands/*.md` into
`~/.config/opencode/`, installs the background dispatcher for the
detected platform, and finishes with a `pablo doctor` run.
`./bin/uninstall.sh` reverses it (only removing symlinks/files PABLO
created; `~/.pablo` data is kept).

Per-platform dispatcher (the engine itself is OS-portable — guarded by
`tests/test_portability.py`):

| | Linux | macOS |
|---|---|---|
| scheduler | systemd user timer (`systemd/`, symlinked + enabled) | launchd LaunchAgent (`launchd/*.template` rendered to `~/Library/LaunchAgents/com.pablo.dispatch.plist`, `StartInterval` 300) |
| logs | `journalctl --user -u pablo-dispatch.service` | `~/.pablo/logs/dispatch.log` (+ `.err.log`) |
| status | `systemctl --user list-timers pablo-dispatch.timer` | `launchctl print gui/$UID/com.pablo.dispatch` |

## macOS first-install smoke checklist

The launchd path was written portable-by-construction on Linux — walk
this once on the Mac:

1. Prerequisites: Python 3.12 + Poetry, `gh` (+ `gh auth login`),
   the Atlassian CLI `acli` (+ `acli auth login` — covers both Jira and
   Confluence under one OAuth grant), the Orca and opencode apps.
2. `./bin/install.sh` → expect "com.pablo.dispatch loaded".
3. `pablo doctor` → all ✅.
4. `tail -f ~/.pablo/logs/dispatch.log` across one 5-minute tick → a
   clean dispatch run.
5. `pablo start …` + `/pablo-tasks` round trip on a real project.

## Orca visibility (verified 2026-07-26)

Orca's `--worktree path:` selector only resolves worktrees of repos
**registered in Orca** (`orca repo add <path>`; registered repos have
`externalWorktreeVisibility: show`). Register each managed project's repo
in Orca so PABLO's agent runs appear as Orca terminals; for unregistered
repos PABLO transparently falls back to headless `opencode run` (see
[agent-running.md](agent-running.md)).
