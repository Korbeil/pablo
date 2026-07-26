"""Linear provider, backed by schpet's ``linear`` CLI.

Exact subcommands used (documented in @README.md; the CLI was chosen but not
yet installed on this machine — verify these against ``linear --help`` on
first install and adjust here if they differ):
- ``linear issue view <KEY> --json``
- ``linear issue list --assignee <identity> --json``
- ``linear auth status``  — auth check
"""

from __future__ import annotations

import json
import re
from datetime import datetime

from pablo.config import ProjectConfig
from pablo.model import Issue, Task
from pablo.providers import parse_ts, run_cli

_ISSUE_URL_RE = re.compile(r"https?://linear\.app/[^/]+/issue/([A-Z][A-Z0-9]*-\d+)")


class LinearProvider:
    name = "linear"

    def match_url(self, url: str, cfg: ProjectConfig) -> str | None:
        match = _ISSUE_URL_RE.match(url)
        if not match:
            return None
        key = match.group(1)
        if key.split("-")[0] != (cfg.project_key or ""):
            return None
        return key

    def _view(self, key: str) -> dict:
        return json.loads(run_cli(["linear", "issue", "view", key, "--json"]))

    def _issue_from_json(self, data: dict, cfg: ProjectConfig) -> Issue:
        return Issue(
            provider=self.name,
            key=data["identifier"],
            url=data.get("url", ""),
            title=data["title"],
            project_key=cfg.project_key,
            status=(data.get("state") or {}).get("name"),
        )

    def get_issue(self, ref: str, cfg: ProjectConfig) -> Issue:
        return self._issue_from_json(self._view(ref), cfg)

    def list_assigned(self, cfg: ProjectConfig) -> list[Issue]:
        out = run_cli(
            ["linear", "issue", "list", "--assignee", cfg.identity, "--json"]
        )
        return [self._issue_from_json(item, cfg) for item in json.loads(out)]

    def issue_status(self, key: str, cfg: ProjectConfig) -> str:
        return self._view(key)["state"]["name"]

    def failure_signal_events(self, task: Task, cfg: ProjectConfig) -> list[datetime]:
        """Timestamps of history transitions into ``testing.failure_signal``."""
        if task.issue is None or not cfg.failure_signal:
            return []
        data = self._view(task.issue.key)
        stamps = [
            parse_ts(entry["createdAt"])
            for entry in data.get("history", [])
            if (entry.get("toState") or {}).get("name") == cfg.failure_signal
        ]
        return sorted(stamps)

    def cli_name(self) -> str:
        return "linear"

    def auth_check_cmd(self) -> list[str]:
        return ["linear", "auth", "status"]
