#!/usr/bin/env bash
# PABLO uninstaller (Linux + macOS): removes only symlinks that resolve
# into this repository (under ~/.config/opencode, ~/.claude and ~/.copilot,
# whichever runtime was installed), the launchd plist PABLO generated and the
# generated ~/.pablo/claude/, and disables the dispatcher. Runtime data in
# ~/.pablo is kept.
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd -P)"
OPENCODE_DIR="${HOME}/.config/opencode"
CLAUDE_DIR="${CLAUDE_CONFIG_DIR:-${HOME}/.claude}"
COPILOT_DIR="${COPILOT_HOME:-${HOME}/.copilot}"
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
for d in "$REPO_DIR"/opencode/skills/*/; do
    remove_if_ours "$OPENCODE_DIR/skill/$(basename "$d")"
    remove_if_ours "$CLAUDE_DIR/skills/$(basename "$d")"
    remove_if_ours "$COPILOT_DIR/skills/$(basename "$d")"
done
# Copilot runtime: the pablo-<agent> links and the command skills.
for f in "$REPO_DIR"/copilot/agents/*.agent.md; do
    [ -e "$f" ] || continue
    remove_if_ours "$COPILOT_DIR/agents/$(basename "$f")"
done
for f in "$REPO_DIR"/opencode/commands/*.md; do
    remove_if_ours "$COPILOT_DIR/skills/$(basename "$f" .md)"
done
# Claude Code runtime: the command links, then the generated --agents file.
for f in "$REPO_DIR"/opencode/commands/*.md; do
    remove_if_ours "$CLAUDE_DIR/commands/$(basename "$f")"
done
if [ -d "${HOME}/.pablo/claude" ]; then
    rm -rf "${HOME}/.pablo/claude"
    info "removed ${HOME}/.pablo/claude"
fi

remove_if_ours "$BIN_DIR/pablo"

# The PATH block install.sh may have appended (between its markers).
for rc in "$HOME/.bashrc" "$HOME/.bash_profile" "${ZDOTDIR:-$HOME}/.zshrc"; do
    if [ -f "$rc" ] && grep -qF '# >>> pablo >>>' "$rc"; then
        tmp="$(mktemp)"
        awk '/^# >>> pablo >>>$/{skip=1; next} /^# <<< pablo <<<$/{skip=0; next} !skip' "$rc" > "$tmp"
        cat "$tmp" > "$rc" # cat, not mv: keeps the file's permissions and any symlink
        rm "$tmp"
        info "removed the PABLO PATH block from $rc"
    fi
done
FISH_RC="$HOME/.config/fish/conf.d/pablo.fish"
if [ -f "$FISH_RC" ] && grep -qF '# >>> pablo >>>' "$FISH_RC"; then
    rm "$FISH_RC"
    info "removed $FISH_RC"
fi

info "done. ~/.pablo (state, stamps, worktrees, logs, config) was left in place."
