"""Jira provider, backed by the ``jira`` CLI (ankitpokhrel/jira-cli).

Exact subcommands used (documented in @README.md):
- ``jira issue view <KEY> --raw``  — issue JSON incl. changelog when present
- ``jira issue list --project <KEY> --assignee <identity> --raw``
- ``jira me``                      — auth check
"""

from __future__ import annotations

import json
import re
from datetime import datetime

from pablo.config import ProjectConfig
from pablo.model import Issue, Task
from pablo.providers import parse_ts, run_cli

_BROWSE_RE = re.compile(r"https?://[^/]+/browse/([A-Z][A-Z0-9]*-\d+)")


class JiraProvider:
    name = "jira"

    def match_url(self, url: str, cfg: ProjectConfig) -> str | None:
        match = _BROWSE_RE.match(url)
        if not match:
            return None
        key = match.group(1)
        if key.split("-")[0] != (cfg.project_key or ""):
            return None
        return key

    def _view_raw(self, key: str) -> dict:
        return json.loads(run_cli(["jira", "issue", "view", key, "--raw"]))

    def _issue_from_raw(self, data: dict, cfg: ProjectConfig) -> Issue:
        key = data["key"]
        return Issue(
            provider=self.name,
            key=key,
            url=f"https://jira/browse/{key}"
            if "self" not in data
            else re.sub(r"/rest/api/.*$", f"/browse/{key}", data["self"]),
            title=data["fields"]["summary"],
            project_key=cfg.project_key,
        )

    def get_issue(self, ref: str, cfg: ProjectConfig) -> Issue:
        return self._issue_from_raw(self._view_raw(ref), cfg)

    def list_assigned(self, cfg: ProjectConfig) -> list[Issue]:
        out = run_cli(
            ["jira", "issue", "list", "--project", cfg.project_key or "",
             "--assignee", cfg.identity, "--raw"]
        )
        data = json.loads(out)
        items = data.get("issues", data if isinstance(data, list) else [])
        return [self._issue_from_raw(item, cfg) for item in items]

    def issue_status(self, key: str, cfg: ProjectConfig) -> str:
        return self._view_raw(key)["fields"]["status"]["name"]

    def failure_signal_events(self, task: Task, cfg: ProjectConfig) -> list[datetime]:
        """Timestamps of changelog transitions into ``testing.failure_signal``."""
        if task.issue is None or not cfg.failure_signal:
            return []
        data = self._view_raw(task.issue.key)
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
        return "jira"

    def auth_check_cmd(self) -> list[str]:
        return ["jira", "me"]
