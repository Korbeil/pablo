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

# Portable `readlink -f` (BSD readlink lacks -f on older macOS).
resolve_path() {
    php -r 'echo realpath($argv[1]);' "$1"
}

# 1. PHP dependencies --------------------------------------------------------
info "composer install (no-dev)"
(cd "$REPO_DIR/app" && composer install --no-dev --quiet)

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
    systemctl --user daemon-reload
    systemctl --user enable --now pablo-dispatch.timer
    info "pablo-dispatch.timer enabled (every 5 minutes; logs: journalctl --user -u pablo-dispatch.service)"
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
