#!/usr/bin/env bash
# PABLO uninstaller (Linux + macOS): removes only symlinks that resolve
# into this repository (and the launchd plist PABLO generated) and
# disables the dispatcher. Runtime data in ~/.pablo is kept.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
OPENCODE_DIR="${HOME}/.config/opencode"
BIN_DIR="${HOME}/.local/bin"
OS="$(uname -s)"

info() { printf '➜ %s\n' "$*"; }

resolve_path() {
    python3 -c 'import os, sys; print(os.path.realpath(sys.argv[1]))' "$1"
}

remove_if_ours() {
    local dest="$1"
    if [ -L "$dest" ] && [[ "$(resolve_path "$dest")" == "$REPO_DIR"/* ]]; then
        rm "$dest"
        info "removed $dest"
    fi
}

case "$OS" in
Linux)
    SYSTEMD_DIR="${HOME}/.config/systemd/user"
    systemctl --user disable --now pablo-dispatch.timer 2>/dev/null || true
    remove_if_ours "$SYSTEMD_DIR/pablo-dispatch.service"
    remove_if_ours "$SYSTEMD_DIR/pablo-dispatch.timer"
    systemctl --user disable --now pablo-web.service 2>/dev/null || true
    remove_if_ours "$SYSTEMD_DIR/pablo-web.service"
    systemctl --user daemon-reload
    ;;
Darwin)
    PLIST="${HOME}/Library/LaunchAgents/com.pablo.dispatch.plist"
    launchctl bootout "gui/$(id -u)/com.pablo.dispatch" 2>/dev/null || true
    if [ -f "$PLIST" ] && grep -q "marker: com.pablo.dispatch" "$PLIST"; then
        rm "$PLIST"
        info "removed $PLIST"
    fi
    WEB_PLIST="${HOME}/Library/LaunchAgents/com.pablo.web.plist"
    launchctl bootout "gui/$(id -u)/com.pablo.web" 2>/dev/null || true
    if [ -f "$WEB_PLIST" ] && grep -q "marker: com.pablo.web" "$WEB_PLIST"; then
        rm "$WEB_PLIST"
        info "removed $WEB_PLIST"
    fi
    ;;
esac

for f in "$REPO_DIR"/opencode/agents/*.md "$REPO_DIR"/opencode/commands/*.md; do
    remove_if_ours "$OPENCODE_DIR/agents/$(basename "$f")"
    remove_if_ours "$OPENCODE_DIR/commands/$(basename "$f")"
done

remove_if_ours "$BIN_DIR/pablo"

info "done. ~/.pablo (state, stamps, worktrees, logs) was left in place."
