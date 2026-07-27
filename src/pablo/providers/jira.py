"""Jira provider, backed by the Atlassian MCP server.

Spec amendment 2026-07-26: Jira is the one provider that goes through an
MCP server (https://mcp.atlassian.com/v1/mcp via the mcp-remote bridge)
instead of a CLI. Tools used: ``getAccessibleAtlassianResources`` (cloud
id, matched against ``issue_tracker.site`` when set), ``getJiraIssue``,
``searchJiraIssuesUsingJql``. Auth is owned and cached by the bridge
(``~/.mcp-auth``); PABLO stores no tokens. Failure-signal detection uses
changelog timestamps when ``getJiraIssue`` responses carry a changelog,
otherwise the poller's observed-status-transition fallback
(``signal_via_status``) covers it.
"""

from __future__ import annotations

import json
import os
import re
from datetime import datetime
from pathlib import Path
from typing import Any

from pablo import PabloError, mcpclient
from pablo.config import ProjectConfig
from pablo.model import Issue, Task
from pablo.providers import parse_ts

_BROWSE_RE = re.compile(r"https?://[^/]+/browse/([A-Z][A-Z0-9]*-\d+)")


def _cache_root() -> Path:
    override = os.environ.get("PABLO_CACHE_DIR")
    return Path(override) if override else Path("~/.pablo/cache").expanduser()


def _cloud_id_cache_path() -> Path:
    return _cache_root() / "jira-cloud-ids.json"


def _load_cloud_id_cache() -> dict[str, str]:
    path = _cloud_id_cache_path()
    if not path.is_file():
        return {}
    try:
        return json.loads(path.read_text())
    except json.JSONDecodeError:
        return {}


def _save_cloud_id_cache(cache: dict[str, str]) -> None:
    path = _cloud_id_cache_path()
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix(".json.tmp")
    tmp.write_text(json.dumps(cache, indent=2) + "\n")
    os.replace(tmp, path)


def call(tool: str, args: dict) -> Any:
    """One MCP tool call, retried on transient connection failures.
    Module-level for test patching."""
    return mcpclient.call_tool_with_retry(tool, args)


class JiraProvider:
    name = "jira"
    signal_via_status = True  # poller may fall back to status-transition detection

    def __init__(self) -> None:
        self._cloud_ids: dict[str, str] = {}

    def _cloud_id(self, cfg: ProjectConfig) -> str:
        cache_key = cfg.site or "<first>"
        if cache_key in self._cloud_ids:
            return self._cloud_ids[cache_key]
        disk_cache = _load_cloud_id_cache()
        if cache_key in disk_cache:
            self._cloud_ids[cache_key] = disk_cache[cache_key]
            return disk_cache[cache_key]
        resources = call("getAccessibleAtlassianResources", {})
        if isinstance(resources, dict):
            resources = resources.get("resources") or resources.get("values") or []
        if not resources:
            raise PabloError(
                "jira mcp: no accessible Atlassian sites for this account"
            )
        chosen = None
        if cfg.site:
            for resource in resources:
                if cfg.site in (resource.get("url") or ""):
                    chosen = resource
                    break
            if chosen is None:
                raise PabloError(
                    f"jira mcp: site {cfg.site!r} not among accessible sites: "
                    + ", ".join(r.get("url", "?") for r in resources)
                )
        else:
            chosen = resources[0]
        self._cloud_ids[cache_key] = chosen["id"]
        disk_cache[cache_key] = chosen["id"]
        _save_cloud_id_cache(disk_cache)
        return chosen["id"]

    def _get(self, key: str, cfg: ProjectConfig) -> dict:
        return call(
            "getJiraIssue",
            {"cloudId": self._cloud_id(cfg), "issueIdOrKey": key},
        )

    def _issue_from_fields(self, data: dict, cfg: ProjectConfig) -> Issue:
        key = data["key"]
        fields = data.get("fields") or {}
        site = cfg.site or "jira"
        return Issue(
            provider=self.name,
            key=key,
            url=f"https://{site}/browse/{key}",
            title=fields.get("summary", ""),
            project_key=cfg.project_key,
            status=(fields.get("status") or {}).get("name"),
        )

    def match_url(self, url: str, cfg: ProjectConfig) -> str | None:
        match = _BROWSE_RE.match(url)
        if not match:
            return None
        key = match.group(1)
        if key.split("-")[0] != (cfg.project_key or ""):
            return None
        return key

    def get_issue(self, ref: str, cfg: ProjectConfig) -> Issue:
        return self._issue_from_fields(self._get(ref, cfg), cfg)

    def list_assigned(self, cfg: ProjectConfig) -> list[Issue]:
        result = call(
            "searchJiraIssuesUsingJql",
            {
                "cloudId": self._cloud_id(cfg),
                "jql": (
                    f"project = {cfg.project_key} AND assignee = currentUser() "
                    f"ORDER BY updated DESC"
                ),
                "maxResults": 50,
            },
        )
        items = result.get("issues", []) if isinstance(result, dict) else result
        return [self._issue_from_fields(item, cfg) for item in items]

    def issue_status(self, key: str, cfg: ProjectConfig) -> str:
        fields = self._get(key, cfg).get("fields") or {}
        return (fields.get("status") or {}).get("name", "?")

    def failure_signal_events(self, task: Task, cfg: ProjectConfig) -> list[datetime]:
        """Changelog transitions into ``testing.failure_signal``, when the
        MCP response carries a changelog; [] otherwise (the poller's
        observed-transition fallback takes over)."""
        if task.issue is None or not cfg.failure_signal:
            return []
        data = self._get(task.issue.key, cfg)
        histories = (data.get("changelog") or {}).get("histories", [])
        stamps = [
            parse_ts(history["created"])
            for history in histories
            if any(
                item.get("field") == "status"
                and item.get("toString") == cfg.failure_signal
                for item in history.get("items", [])
            )
        ]
        return sorted(stamps)

    def cli_name(self) -> str:
        return "jira-mcp"

    def auth_check_cmd(self) -> list[str]:
        # Not used: doctor has a dedicated MCP check for jira.
        return []
