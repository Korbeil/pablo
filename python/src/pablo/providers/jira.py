"""Jira provider, backed by the ``acli`` CLI (Atlassian CLI).

Jira access goes through `acli jira workitem …` (OAuth owned and cached by
acli itself — `acli auth login`; PABLO stores no tokens).
acli does *not* expose a work-item changelog in its JSON output (probed live
2026-07-29: the `changelog` field is always null), so failure-signal
detection relies on the poller's observed-status-transition fallback
(``signal_via_status = True``); ``failure_signal_events`` always returns [].
"""

from __future__ import annotations

import json
import re
from datetime import datetime

from pablo.config import ProjectConfig
from pablo.model import Issue, Task
from pablo.providers import parse_ts, run_cli

JIRA_CALL_TIMEOUT_S = 30

_BROWSE_RE = re.compile(r"https?://[^/]+/browse/([A-Z][A-Z0-9]*-\d+)")
_FIELDS = "summary,status"


class JiraProvider:
    name = "jira"
    signal_via_status = True  # acli carries no changelog → poller fallback path

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
        out = run_cli(
            ["acli", "jira", "workitem", "view", ref, "--json", "--fields", _FIELDS],
            timeout=JIRA_CALL_TIMEOUT_S,
        )
        return self._issue_from_fields(json.loads(out), cfg)

    def list_assigned(self, cfg: ProjectConfig) -> list[Issue]:
        jql = (
            f"project = {cfg.project_key} AND assignee = currentUser() "
            f"ORDER BY updated DESC"
        )
        out = run_cli(
            ["acli", "jira", "workitem", "search", "--jql", jql,
             "--fields", _FIELDS, "--json", "--limit", "50"],
            timeout=JIRA_CALL_TIMEOUT_S,
        )
        items = json.loads(out)
        return [self._issue_from_fields(item, cfg) for item in items]

    def issue_status(self, key: str, cfg: ProjectConfig) -> str:
        out = run_cli(
            ["acli", "jira", "workitem", "view", key, "--json", "--fields", "status"],
            timeout=JIRA_CALL_TIMEOUT_S,
        )
        fields = json.loads(out).get("fields") or {}
        return (fields.get("status") or {}).get("name", "?")

    def failure_signal_events(self, task: Task, cfg: ProjectConfig) -> list[datetime]:
        """acli exposes no changelog → always []; the poller's
        observed-transition fallback (`signal_via_status = True`) is the
        active failure-detection path."""
        return []

    def cli_name(self) -> str:
        return "acli"

    def auth_check_cmd(self) -> list[str]:
        return ["acli", "jira", "auth", "status"]