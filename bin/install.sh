#!/usr/bin/env bash
# PABLO installer (Linux + macOS). Idempotent. Creates ONLY new
# symlinks/units — it refuses to touch any pre-existing file in
# ~/.config/opencode that isn't already a symlink into this repository
# (spec: originals stay untouched).
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
OPENCODE_DIR="${HOME}/.config/opencode"
BIN_DIR="${HOME}/.local/bin"
OS="$(uname -s)"

info() { printf '➜ %s\n' "$*"; }
die() { printf '❌ %s\n' "$*" >&2; exit 1; }

# Install the web dashboard as a background service only when explicitly
# requested: ./bin/install.sh --with-web
WITH_WEB=false
for arg in "$@"; do
    case "$arg" in
    --with-web)
        WITH_WEB=true
        ;;
    *)
        die "unknown option: $arg (supported: --with-web)"
        ;;
    esac
done

# Portable `readlink -f` (BSD readlink lacks -f on older macOS).
resolve_path() {
    php -r 'echo realpath($argv[1]);' "$1"
}

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

# 3. OpenCode agents + commands ---------------------------------------------
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

for f in "$REPO_DIR"/opencode/agents/*.md; do
    link_into "$f" "$OPENCODE_DIR/agents"
done
for f in "$REPO_DIR"/opencode/commands/*.md; do
    link_into "$f" "$OPENCODE_DIR/commands"
done

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
    sed -e "s|@PABLO_BIN@|$BIN_DIR/pablo|g" -e "s|@LOG_DIR@|$LOG_DIR|g" \
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
        sed -e "s|@PABLO_BIN@|$BIN_DIR/pablo|g" -e "s|@LOG_DIR@|$LOG_DIR|g" \
            "$REPO_DIR/launchd/com.pablo.web.plist.template" > "$WEB_PLIST"
        launchctl bootout "gui/$(id -u)/com.pablo.web" 2>/dev/null || true
        launchctl bootstrap "gui/$(id -u)" "$WEB_PLIST"
        info "com.pablo.web loaded (dashboard on http://127.0.0.1:8321; logs: $LOG_DIR/web.log)"
    fi
    ;;
*)
    die "unsupported platform: $OS (Linux and Darwin are supported)"
    ;;
esac

# 5. Preflight ---------------------------------------------------------------
info "running pablo system:doctor (a failure here is a warning at install time —"
info "projects may not be configured yet):"
"$BIN_DIR/pablo" system:doctor || true

info "done. Add project configs under $REPO_DIR/app/projects/ to get started."
