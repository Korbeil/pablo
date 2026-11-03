#!/usr/bin/env bash
# PABLO installer (Linux + macOS). Idempotent. Creates ONLY new
# symlinks/units — it refuses to touch any pre-existing file in
# ~/.config/opencode (OpenCode runtime) or ~/.claude (Claude Code runtime)
# that isn't already a symlink into this repository (spec: originals stay
# untouched).
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
OPENCODE_DIR="${HOME}/.config/opencode"
CLAUDE_DIR="${CLAUDE_CONFIG_DIR:-${HOME}/.claude}"
BIN_DIR="${HOME}/.local/bin"
OS="$(uname -s)"

info() { printf '➜ %s\n' "$*"; }
die() { printf '❌ %s\n' "$*" >&2; exit 1; }

# The web dashboard is installed as a background service by default; opt out
# with: ./bin/install.sh --without-web (which also removes a previously
# installed dashboard service).
WITH_WEB=true
for arg in "$@"; do
    case "$arg" in
    --without-web)
        WITH_WEB=false
        ;;
    --with-web)
        info "--with-web is now the default (opt out with --without-web)"
        ;;
    *)
        die "unknown option: $arg (supported: --without-web)"
        ;;
    esac
done

# Portable `readlink -f` (BSD readlink lacks -f on older macOS).
resolve_path() {
    php -r 'echo realpath($argv[1]);' "$1"
}

# 0. Runtime layout ----------------------------------------------------------
# Seed a usable ~/.pablo/ on first install: the global config + the projects
# dir. Never overwrite an existing config.yaml.
PABLO_DIR="${HOME}/.pablo"
PABLO_CONFIG_FILE="${PABLO_CONFIG:-$PABLO_DIR/config.yaml}"
PABLO_PROJECTS_DIR_OVERRIDE="${PABLO_PROJECTS_DIR:-$PABLO_DIR/projects}"

if [ ! -d "$PABLO_PROJECTS_DIR_OVERRIDE" ]; then
    mkdir -p "$PABLO_PROJECTS_DIR_OVERRIDE"
    info "created $PABLO_PROJECTS_DIR_OVERRIDE"
fi

if [ ! -f "$PABLO_CONFIG_FILE" ]; then
    mkdir -p "$(dirname "$PABLO_CONFIG_FILE")"
    cp "$REPO_DIR/examples/config.yaml" "$PABLO_CONFIG_FILE"
    info "wrote default $PABLO_CONFIG_FILE (edit it to set your default agent model, PR locale, sync strategy, ...)"
    # Pick the agent runtime the user actually has: OpenCode is the default,
    # so only switch when Claude Code is installed and OpenCode is not (the
    # example's OpenCode model id then becomes a Claude Code alias).
    if ! command -v opencode >/dev/null 2>&1 && command -v claude >/dev/null 2>&1; then
        sed -e 's|^default_model:.*$|default_model: sonnet|' "$PABLO_CONFIG_FILE" > "$PABLO_CONFIG_FILE.tmp"
        mv "$PABLO_CONFIG_FILE.tmp" "$PABLO_CONFIG_FILE"
        printf '\nagent_runtime: claude-code\n' >> "$PABLO_CONFIG_FILE"
        info "Claude Code found (and no OpenCode): set agent_runtime: claude-code, default_model: sonnet"
    fi
    # Pick the agent backend the user actually has: Orca is the default, so
    # only switch when OpenChamber is installed and Orca is not — and never
    # under Claude Code, which OpenChamber cannot run.
    if ! command -v orca >/dev/null 2>&1 && command -v openchamber >/dev/null 2>&1 && command -v opencode >/dev/null 2>&1; then
        printf '\nagent_backend: openchamber\n' >> "$PABLO_CONFIG_FILE"
        info "OpenChamber found (and no Orca): set agent_backend: openchamber"
    elif ! command -v orca >/dev/null 2>&1; then
        info "⚠ neither Orca nor OpenChamber found: agents will run headless until you install one"
    fi
else
    info "keeping existing $PABLO_CONFIG_FILE"
fi

# The agent runtime decides what gets generated and linked below:
# PABLO_AGENT_RUNTIME, else agent_runtime in the global config, else opencode.
RUNTIME="${PABLO_AGENT_RUNTIME:-}"
if [ -z "$RUNTIME" ] && [ -f "$PABLO_CONFIG_FILE" ]; then
    RUNTIME="$(sed -n 's/^agent_runtime:[[:space:]]*["'"'"']\{0,1\}\([a-z-]*\).*$/\1/p' "$PABLO_CONFIG_FILE" | head -n 1)"
fi
RUNTIME="${RUNTIME:-opencode}"
case "$RUNTIME" in
opencode | claude-code) info "agent runtime: $RUNTIME" ;;
*) die "unknown agent_runtime '$RUNTIME' (expected opencode or claude-code)" ;;
esac

# 1. PHP dependencies --------------------------------------------------------
info "composer install (no-dev)"
(cd "$REPO_DIR/app" && composer install --no-dev --quiet)

# 1b. Container cache --------------------------------------------------------
# The DI container is compiled into app/var/cache/<env>. A stray `sudo pablo`
# leaves root-owned files there and every later user-run dies with "Unable to
# write in the cache directory" — check before we warm, since a kernel that
# cannot boot also cannot run system:doctor to tell you why.
CACHE_DIR="$REPO_DIR/app/var/cache"
if [ -e "$CACHE_DIR" ] && [ ! -w "$CACHE_DIR" ]; then
    die "$CACHE_DIR is not writable by $(id -un) (root-owned from a sudo run?). Fix with: sudo rm -rf '$CACHE_DIR'"
fi

# Warm it here, as the installing user, so the first systemd dispatch tick
# doesn't pay for it and a container compiled from a previous checkout can
# never survive an install.
info "warming the container cache"
php "$REPO_DIR/app/bin/console" cache:clear --env="${PABLO_ENV:-dev}" --no-interaction >/dev/null

# 1c. Agent templates --------------------------------------------------------
info "generating the $RUNTIME agent definitions from project configs"
php "$REPO_DIR/app/bin/pablo" system:generate-agents --runtime "$RUNTIME" --no-interaction 2>/dev/null || true

# 2. pablo on PATH -----------------------------------------------------------
PABLO_BIN="$REPO_DIR/app/bin/pablo"
[ -x "$PABLO_BIN" ] || die "pablo entrypoint not found at $PABLO_BIN"
mkdir -p "$BIN_DIR"
cat > "$BIN_DIR/pablo" <<SHIM
#!/usr/bin/env bash
exec php '$PABLO_BIN' "\$@"
SHIM
chmod +x "$BIN_DIR/pablo"
info "wrote $BIN_DIR/pablo shim -> php $PABLO_BIN"

# 2b. ~/.local/bin on PATH ---------------------------------------------------
# Only when it isn't already there. The block we append is wrapped in markers
# so a re-install never duplicates it and uninstall.sh removes it. fish gets
# its own conf.d file instead, so no existing file is edited.
PATH_MARK_BEGIN='# >>> pablo >>>'
PATH_MARK_END='# <<< pablo <<<'
PATH_RC=""
case ":$PATH:" in
*":$BIN_DIR:"*) ;;
*)
    case "$(basename "${SHELL:-}")" in
    zsh) PATH_RC="${ZDOTDIR:-$HOME}/.zshrc" ;;
    bash)
        if [ "$OS" = Darwin ]; then PATH_RC="$HOME/.bash_profile"; else PATH_RC="$HOME/.bashrc"; fi
        ;;
    fish) PATH_RC="$HOME/.config/fish/conf.d/pablo.fish" ;;
    esac
    if [ -z "$PATH_RC" ]; then
        info "⚠ $BIN_DIR is not on your PATH: add it in your shell config (export PATH=\"$BIN_DIR:\$PATH\")"
    elif [ -f "$PATH_RC" ] && grep -qF "$PATH_MARK_BEGIN" "$PATH_RC"; then
        info "$PATH_RC already puts $BIN_DIR on PATH"
    else
        mkdir -p "$(dirname "$PATH_RC")"
        if [[ "$PATH_RC" == *.fish ]]; then
            printf '%s\n# Added by the PABLO installer (removed by bin/uninstall.sh).\ncontains %s $PATH; or set -gx PATH %s $PATH\n%s\n' \
                "$PATH_MARK_BEGIN" "$BIN_DIR" "$BIN_DIR" "$PATH_MARK_END" > "$PATH_RC"
        else
            printf '\n%s\n# Added by the PABLO installer (removed by bin/uninstall.sh).\ncase ":$PATH:" in *":%s:"*) ;; *) export PATH="%s:$PATH" ;; esac\n%s\n' \
                "$PATH_MARK_BEGIN" "$BIN_DIR" "$BIN_DIR" "$PATH_MARK_END" >> "$PATH_RC"
        fi
        info "added $BIN_DIR to PATH in $PATH_RC"
    fi
    ;;
esac

# 3. Agents, commands and skills (per runtime) ------------------------------
link_into() { # link_into <src-file> <dest-dir>
    local src="$1" dest_dir="$2"
    local dest="$dest_dir/$(basename "$src")"
    if [ -e "$dest" ] && { [ ! -L "$dest" ] || [[ "$(resolve_path "$dest")" != "$REPO_DIR"/* ]]; }; then
        die "$dest exists and is not a PABLO symlink — refusing to overwrite (originals stay untouched)"
    fi
    mkdir -p "$dest_dir"
    ln -sfn "$src" "$dest"
    info "linked $dest"
}

link_skills_into() { # link_skills_into <dest-dir>
    local dest_dir="$1" d name
    for d in "$REPO_DIR"/opencode/skills/*/; do
        name="$(basename "$d")"
        [ -e "$dest_dir/$name" ] && [ ! -L "$dest_dir/$name" ] &&
            die "$dest_dir/$name exists and is not a symlink — refusing to overwrite"
        mkdir -p "$dest_dir"
        ln -sfn "$REPO_DIR/opencode/skills/$name" "$dest_dir/$name"
        info "linked $dest_dir/$name"
    done
}

if [ "$RUNTIME" = claude-code ]; then
    # Only the command and the skill go under ~/.claude. The agents stay out
    # of ~/.claude/agents on purpose (they would show up in your interactive
    # sessions): PABLO passes ~/.pablo/claude/agents.json with --agents.
    [ -f "$PABLO_DIR/claude/agents.json" ] ||
        die "$PABLO_DIR/claude/agents.json was not generated — run: php $REPO_DIR/app/bin/pablo system:generate-agents --runtime claude-code"
    for f in "$REPO_DIR"/claude/commands/*.md; do
        link_into "$f" "$CLAUDE_DIR/commands"
    done
    link_skills_into "$CLAUDE_DIR/skills"
else
    for f in "$REPO_DIR"/opencode/agents/*.md; do
        link_into "$f" "$OPENCODE_DIR/agents"
    done
    for f in "$REPO_DIR"/opencode/commands/*.md; do
        link_into "$f" "$OPENCODE_DIR/commands"
    done
    link_skills_into "$OPENCODE_DIR/skill"
fi

# 4. Background dispatcher (per-platform) ------------------------------------
case "$OS" in
Linux)
    SYSTEMD_DIR="${HOME}/.config/systemd/user"
    mkdir -p "$SYSTEMD_DIR"
    for unit in pablo-dispatch.service pablo-dispatch.timer; do
        ln -sfn "$REPO_DIR/systemd/$unit" "$SYSTEMD_DIR/$unit"
        info "linked $SYSTEMD_DIR/$unit"
    done
    # User units only start at boot if the user lingers; otherwise systemd waits for a
    # login that never comes on a headless box, and everything stays down after a reboot
    # even though it is `enabled`. This is what left the dispatcher and the dashboard
    # dead for ~45 minutes after the 2026-08-12 power cycle on orca.
    loginctl enable-linger "$(id -un)" ||
        info "⚠ could not enable lingering — PABLO units will NOT start until you log in"
    systemctl --user daemon-reload
    systemctl --user enable --now pablo-dispatch.timer
    info "pablo-dispatch.timer enabled (every 5 minutes; logs: journalctl --user -u pablo-dispatch.service)"
    if $WITH_WEB; then
        ln -sfn "$REPO_DIR/systemd/pablo-web.service" "$SYSTEMD_DIR/pablo-web.service"
        info "linked $SYSTEMD_DIR/pablo-web.service"
        systemctl --user daemon-reload
        systemctl --user enable --now pablo-web.service
        info "pablo-web.service enabled (dashboard on http://127.0.0.1:8321; logs: journalctl --user -u pablo-web.service)"
    elif [ -L "$SYSTEMD_DIR/pablo-web.service" ]; then
        systemctl --user disable --now pablo-web.service 2>/dev/null || true
        rm "$SYSTEMD_DIR/pablo-web.service"
        systemctl --user daemon-reload
        info "--without-web: removed pablo-web.service"
    fi
    ;;
Darwin)
    LOG_DIR="${HOME}/.pablo/logs"
    AGENTS_DIR="${HOME}/Library/LaunchAgents"
    PLIST="$AGENTS_DIR/com.pablo.dispatch.plist"
    if [ -e "$PLIST" ] && ! grep -q "marker: com.pablo.dispatch" "$PLIST"; then
        die "$PLIST exists and was not written by PABLO — refusing to overwrite"
    fi
    mkdir -p "$LOG_DIR" "$AGENTS_DIR"
    sed -e "s|@PABLO_BIN@|$BIN_DIR/pablo|g" -e "s|@LOG_DIR@|$LOG_DIR|g" -e "s|@HOME@|$HOME|g" \
        "$REPO_DIR/launchd/com.pablo.dispatch.plist.template" > "$PLIST"
    # bootout first so a re-install picks up the fresh plist (idempotent)
    launchctl bootout "gui/$(id -u)/com.pablo.dispatch" 2>/dev/null || true
    launchctl bootstrap "gui/$(id -u)" "$PLIST"
    info "com.pablo.dispatch loaded (every 5 minutes; logs: $LOG_DIR/dispatch.log)"
    if $WITH_WEB; then
        WEB_PLIST="$AGENTS_DIR/com.pablo.web.plist"
        if [ -e "$WEB_PLIST" ] && ! grep -q "marker: com.pablo.web" "$WEB_PLIST"; then
            die "$WEB_PLIST exists and was not written by PABLO — refusing to overwrite"
        fi
        sed -e "s|@PABLO_BIN@|$BIN_DIR/pablo|g" -e "s|@LOG_DIR@|$LOG_DIR|g" -e "s|@HOME@|$HOME|g" \
            "$REPO_DIR/launchd/com.pablo.web.plist.template" > "$WEB_PLIST"
        launchctl bootout "gui/$(id -u)/com.pablo.web" 2>/dev/null || true
        launchctl bootstrap "gui/$(id -u)" "$WEB_PLIST"
        info "com.pablo.web loaded (dashboard on http://127.0.0.1:8321; logs: $LOG_DIR/web.log)"
    else
        WEB_PLIST="$AGENTS_DIR/com.pablo.web.plist"
        if [ -f "$WEB_PLIST" ] && grep -q "marker: com.pablo.web" "$WEB_PLIST"; then
            launchctl bootout "gui/$(id -u)/com.pablo.web" 2>/dev/null || true
            rm "$WEB_PLIST"
            info "--without-web: removed com.pablo.web"
        fi
    fi
    ;;
*)
    die "unsupported platform: $OS (Linux and Darwin are supported)"
    ;;
esac

# 5. Preflight ---------------------------------------------------------------
info "running pablo system:doctor (a failure here is a warning at install time —"
info "projects may not be configured yet):"
DOCTOR_OK=true
"$BIN_DIR/pablo" system:doctor || DOCTOR_OK=false

echo
info "PABLO is installed. Next steps:"
if [ -n "$PATH_RC" ]; then
    echo "   0. open a new terminal (or run: source $PATH_RC) so the pablo command is found"
fi
if ! $DOCTOR_OK; then
    echo "   •  some checks failed: pablo system:setup walks you through each fix"
fi
echo "   1. pablo project:new               # run it inside your repository"
echo "   2. pablo task:start <issue-url>    # or: pablo task:start --project <name> \"what to do\""
if $WITH_WEB; then
    echo "   3. watch it on http://127.0.0.1:8321"
else
    echo "   3. pablo task:list (or pablo web for the dashboard)"
fi
