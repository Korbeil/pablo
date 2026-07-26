#!/usr/bin/env bash
# PABLO uninstaller: removes only symlinks that resolve into this repository
# and disables the dispatcher timer. Runtime data in ~/.pablo is kept.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OPENCODE_DIR="${HOME}/.config/opencode"
SYSTEMD_DIR="${HOME}/.config/systemd/user"
BIN_DIR="${HOME}/.local/bin"

info() { printf '➜ %s\n' "$*"; }

remove_if_ours() {
    local dest="$1"
    if [ -L "$dest" ] && [[ "$(readlink -f "$dest")" == "$REPO_DIR"/* ]]; then
        rm "$dest"
        info "removed $dest"
    fi
}

systemctl --user disable --now pablo-dispatch.timer 2>/dev/null || true
remove_if_ours "$SYSTEMD_DIR/pablo-dispatch.service"
remove_if_ours "$SYSTEMD_DIR/pablo-dispatch.timer"
systemctl --user daemon-reload

for f in "$REPO_DIR"/opencode/agents/*.md "$REPO_DIR"/opencode/commands/*.md; do
    remove_if_ours "$OPENCODE_DIR/agents/$(basename "$f")"
    remove_if_ours "$OPENCODE_DIR/commands/$(basename "$f")"
done

remove_if_ours "$BIN_DIR/pablo"

info "done. ~/.pablo (state, stamps, worktrees) was left in place."
