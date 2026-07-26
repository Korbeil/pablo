#!/usr/bin/env bash
# PABLO installer. Idempotent. Creates ONLY new symlinks/units — it refuses
# to touch any pre-existing file in ~/.config/opencode that isn't already a
# symlink into this repository (spec: originals stay untouched).
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OPENCODE_DIR="${HOME}/.config/opencode"
SYSTEMD_DIR="${HOME}/.config/systemd/user"
BIN_DIR="${HOME}/.local/bin"

info() { printf '➜ %s\n' "$*"; }
die() { printf '❌ %s\n' "$*" >&2; exit 1; }

# 1. Python environment ------------------------------------------------------
info "poetry install (main deps only)"
(cd "$REPO_DIR" && poetry install --only main --quiet)

# 2. pablo on PATH -----------------------------------------------------------
VENV_PATH="$(cd "$REPO_DIR" && poetry env info --path)"
[ -x "$VENV_PATH/bin/pablo" ] || die "poetry venv has no pablo entry point at $VENV_PATH/bin/pablo"
mkdir -p "$BIN_DIR"
ln -sfn "$VENV_PATH/bin/pablo" "$BIN_DIR/pablo"
info "linked $BIN_DIR/pablo -> $VENV_PATH/bin/pablo"

# 3. OpenCode agents + commands ---------------------------------------------
link_into() { # link_into <src-file> <dest-dir>
    local src="$1" dest_dir="$2"
    local dest="$dest_dir/$(basename "$src")"
    if [ -e "$dest" ] && { [ ! -L "$dest" ] || [[ "$(readlink -f "$dest")" != "$REPO_DIR"/* ]]; }; then
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

# 4. systemd user timer ------------------------------------------------------
mkdir -p "$SYSTEMD_DIR"
for unit in pablo-dispatch.service pablo-dispatch.timer; do
    ln -sfn "$REPO_DIR/systemd/$unit" "$SYSTEMD_DIR/$unit"
    info "linked $SYSTEMD_DIR/$unit"
done
systemctl --user daemon-reload
systemctl --user enable --now pablo-dispatch.timer
info "pablo-dispatch.timer enabled (every 5 minutes)"

# 5. Preflight ---------------------------------------------------------------
info "running pablo doctor (a failure here is a warning at install time —"
info "projects may not be configured yet):"
"$BIN_DIR/pablo" doctor || true

info "done. Add project configs under $REPO_DIR/projects/ to get started."
