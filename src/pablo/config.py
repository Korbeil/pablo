"""Project configuration loading.

Reads ``projects/*.yaml`` (skipping ``default.yaml``, which holds PABLO-wide
defaults) and merges per key: a project value wins, a missing key falls back
to the default. Spec: docs/specification.md "Project types" and
"Configuration defaults".
"""

from __future__ import annotations

import os
from dataclasses import dataclass
from pathlib import Path
from typing import Any

import yaml

from pablo import PabloError

DEFAULTS_FILENAME = "default.yaml"
PROJECT_TYPES = {"work", "open-source", "personal"}
PROVIDERS = {"github", "jira", "linear"}

# Keys eligible for a PABLO-wide default in projects/default.yaml,
# as (section, key) → ProjectConfig attribute.
DEFAULT_ELIGIBLE = {
    ("sync", "strategy"): "sync_strategy",
    ("sync", "auto_apply"): "sync_auto_apply",
    ("sync", "interval_minutes"): "sync_interval",
    ("state_polling", "interval_minutes"): "poll_interval",
    ("review", "bot_whitelist"): "bot_whitelist",
}


@dataclass(frozen=True)
class ProjectConfig:
    name: str
    type: str
    repo_path: Path
    primary_branch: str
    worktrees_root: Path
    provider: str
    identity: str
    project_key: str | None
    sync_strategy: str
    sync_auto_apply: bool
    sync_interval: int
    poll_interval: int
    failure_signal: str | None
    bot_whitelist: list[str]


def projects_dir() -> Path:
    override = os.environ.get("PABLO_PROJECTS_DIR")
    if override:
        return Path(override)
    return Path(__file__).resolve().parents[2] / "projects"


def load_projects(directory: Path | None = None) -> dict[str, ProjectConfig]:
    directory = directory if directory is not None else projects_dir()
    if not directory.is_dir():
        raise PabloError(f"projects directory not found: {directory}")
    defaults = _load_yaml(directory / DEFAULTS_FILENAME) if (directory / DEFAULTS_FILENAME).exists() else {}
    projects: dict[str, ProjectConfig] = {}
    for path in sorted(directory.glob("*.yaml")):
        if path.name == DEFAULTS_FILENAME:
            continue
        cfg = _parse_project(path, defaults)
        if cfg.name in projects:
            raise PabloError(f"{path.name}: duplicate project name {cfg.name!r}")
        projects[cfg.name] = cfg
    return projects


def _load_yaml(path: Path) -> dict[str, Any]:
    try:
        data = yaml.safe_load(path.read_text()) or {}
    except yaml.YAMLError as exc:
        raise PabloError(f"{path.name}: invalid YAML: {exc}") from exc
    if not isinstance(data, dict):
        raise PabloError(f"{path.name}: expected a mapping at top level")
    return data


def _require(data: dict[str, Any], dotted: str, path: Path) -> Any:
    node: Any = data
    for part in dotted.split("."):
        if not isinstance(node, dict) or part not in node:
            raise PabloError(f"{path.name}: missing required key {dotted!r}")
        node = node[part]
    return node


def _merged(data: dict[str, Any], defaults: dict[str, Any], section: str, key: str) -> Any:
    for source in (data, defaults):
        value = source.get(section)
        if isinstance(value, dict) and key in value:
            return value[key]
    raise PabloError(
        f"no value for {section}.{key} — set it in the project config or "
        f"projects/{DEFAULTS_FILENAME}"
    )


def _parse_project(path: Path, defaults: dict[str, Any]) -> ProjectConfig:
    data = _load_yaml(path)

    name = _require(data, "name", path)
    type_ = _require(data, "type", path)
    if type_ not in PROJECT_TYPES:
        raise PabloError(
            f"{path.name}: unknown type {type_!r} (expected one of {sorted(PROJECT_TYPES)})"
        )

    repo_path = Path(str(_require(data, "repo.path", path))).expanduser()
    primary_branch = _require(data, "repo.primary_branch", path)

    provider = _require(data, "issue_tracker.provider", path)
    if provider not in PROVIDERS:
        raise PabloError(
            f"{path.name}: unknown provider {provider!r} (expected one of {sorted(PROVIDERS)})"
        )
    identity = _require(data, "issue_tracker.identity", path)
    project_key = data.get("issue_tracker", {}).get("project_key")
    if project_key is None:
        # Required for jira/linear (the issue key prefix) and for github
        # (branch prefix, since GitHub has no native project key).
        raise PabloError(f"{path.name}: missing required key 'issue_tracker.project_key'")

    raw_root = data.get("worktrees_root")
    if raw_root:
        worktrees_root = Path(str(raw_root)).expanduser()
    else:
        worktrees_root = Path("~/.pablo/worktrees").expanduser() / repo_path.name

    sync_interval = _merged(data, defaults, "sync", "interval_minutes")
    poll_interval = _merged(data, defaults, "state_polling", "interval_minutes")
    strategy = _merged(data, defaults, "sync", "strategy")
    if strategy not in {"rebase", "merge"}:
        raise PabloError(f"{path.name}: sync.strategy must be 'rebase' or 'merge', got {strategy!r}")

    return ProjectConfig(
        name=str(name),
        type=str(type_),
        repo_path=repo_path,
        primary_branch=str(primary_branch),
        worktrees_root=worktrees_root,
        provider=str(provider),
        identity=str(identity),
        project_key=str(project_key),
        sync_strategy=str(strategy),
        sync_auto_apply=bool(_merged(data, defaults, "sync", "auto_apply")),
        sync_interval=int(sync_interval),
        poll_interval=int(poll_interval),
        failure_signal=(data.get("testing", {}) or {}).get("failure_signal"),
        bot_whitelist=list(_merged(data, defaults, "review", "bot_whitelist")),
    )
