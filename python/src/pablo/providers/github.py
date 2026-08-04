"""GitHub issues provider, backed by the ``gh`` CLI."""

from __future__ import annotations

import json
import re
from datetime import datetime

from pablo.config import ProjectConfig
from pablo.gitrepo import origin_url
from pablo.model import Issue, Task
from pablo.providers import parse_ts, run_cli

GH_CALL_TIMEOUT_S = 20

_URL_RE = re.compile(r"github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?(?:/|$)")
_ISSUE_URL_RE = re.compile(r"https?://github\.com/([^/]+)/([^/]+)/issues/(\d+)")

_STATUS = {"OPEN": "To Do", "CLOSED": "Done"}


def repo_slug(cfg: ProjectConfig) -> str:
    """``owner/repo`` derived from the project's origin remote."""
    url = origin_url(cfg.repo_path)
    if not url:
        from pablo import PabloError

        raise PabloError(f"{cfg.name}: repo at {cfg.repo_path} has no origin remote")
    match = _URL_RE.search(url)
    if not match:
        from pablo import PabloError

        raise PabloError(f"{cfg.name}: origin remote is not a GitHub URL: {url}")
    return f"{match.group(1)}/{match.group(2)}"


class GithubProvider:
    name = "github"

    def match_url(self, url: str, cfg: ProjectConfig) -> str | None:
        match = _ISSUE_URL_RE.match(url)
        if not match:
            return None
        owner, repo, number = match.groups()
        try:
            slug = repo_slug(cfg)
        except Exception:
            return None
        if f"{owner}/{repo}".lower() != slug.lower():
            return None
        return number

    def get_issue(self, ref: str, cfg: ProjectConfig) -> Issue:
        out = run_cli(
            ["gh", "issue", "view", ref, "--repo", repo_slug(cfg),
             "--json", "number,title,state,url"],
            timeout=GH_CALL_TIMEOUT_S,
        )
        data = json.loads(out)
        return Issue(
            provider=self.name,
            key=str(data["number"]),
            url=data["url"],
            title=data["title"],
            project_key=cfg.project_key,
            status=_STATUS.get(data["state"], data["state"]),
        )

    def list_assigned(self, cfg: ProjectConfig) -> list[Issue]:
        out = run_cli(
            ["gh", "issue", "list", "--repo", repo_slug(cfg),
             "--assignee", cfg.identity, "--state", "all",
             "--json", "number,title,state,url", "--limit", "100"],
            timeout=GH_CALL_TIMEOUT_S,
        )
        return [
            Issue(
                provider=self.name,
                key=str(item["number"]),
                url=item["url"],
                title=item["title"],
                project_key=cfg.project_key,
                status=_STATUS.get(item["state"], item["state"]),
            )
            for item in json.loads(out)
        ]

    def issue_status(self, key: str, cfg: ProjectConfig) -> str:
        out = run_cli(
            ["gh", "issue", "view", key, "--repo", repo_slug(cfg), "--json", "state"],
            timeout=GH_CALL_TIMEOUT_S,
        )
        state = json.loads(out)["state"]
        return _STATUS.get(state, state)

    def failure_signal_events(self, task: Task, cfg: ProjectConfig) -> list[datetime]:
        """Timestamps of ``testing.failure_signal`` label applications on the PR."""
        if task.pr_number is None or not cfg.failure_signal:
            return []
        out = run_cli(
            ["gh", "api", f"repos/{repo_slug(cfg)}/issues/{task.pr_number}/events",
             "--paginate"],
            timeout=GH_CALL_TIMEOUT_S,
        )
        stamps = [
            parse_ts(event["created_at"])
            for event in json.loads(out)
            if event.get("event") == "labeled"
            and event.get("label", {}).get("name") == cfg.failure_signal
        ]
        return sorted(stamps)

    def cli_name(self) -> str:
        return "gh"

    def auth_check_cmd(self) -> list[str]:
        return ["gh", "auth", "status"]
