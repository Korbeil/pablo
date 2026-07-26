"""Issue-tracker providers, CLI-first.

Every provider talks to its service exclusively through that service's own
CLI (``gh``, ``jira``, ``linear``) — no MCP servers, no raw API calls, no
stored tokens. When a CLI fails (e.g. not authenticated), its own error and
instructions are surfaced verbatim through PabloError.
"""

from __future__ import annotations

import subprocess
from datetime import datetime
from typing import TYPE_CHECKING, Protocol

from pablo import PabloError

if TYPE_CHECKING:
    from pablo.config import ProjectConfig
    from pablo.model import Issue, Task


def run_cli(argv: list[str], *, check: bool = True) -> str:
    """Run a provider CLI command, surfacing the CLI's own error text."""
    try:
        proc = subprocess.run(argv, capture_output=True, text=True)
    except FileNotFoundError:
        raise PabloError(
            f"{argv[0]!r} is not installed (required for this project's provider)"
        )
    if check and proc.returncode != 0:
        message = proc.stderr.strip() or proc.stdout.strip()
        raise PabloError(f"{argv[0]} failed: {message}")
    return proc.stdout


def parse_ts(value: str) -> datetime:
    """Parse an ISO-8601 timestamp (accepting a trailing Z) to aware UTC."""
    return datetime.fromisoformat(value.replace("Z", "+00:00"))


class Provider(Protocol):
    name: str

    def match_url(self, url: str, cfg: "ProjectConfig") -> str | None: ...

    def get_issue(self, ref: str, cfg: "ProjectConfig") -> "Issue": ...

    def list_assigned(self, cfg: "ProjectConfig") -> list["Issue"]: ...

    def issue_status(self, key: str, cfg: "ProjectConfig") -> str: ...

    def failure_signal_events(
        self, task: "Task", cfg: "ProjectConfig"
    ) -> list[datetime]: ...

    def cli_name(self) -> str: ...

    def auth_check_cmd(self) -> list[str]: ...


PROVIDER_NAMES = ("github", "jira", "linear")


def get_provider(name: str) -> Provider:
    if name == "github":
        from pablo.providers.github import GithubProvider

        return GithubProvider()
    if name == "jira":
        from pablo.providers.jira import JiraProvider

        return JiraProvider()
    if name == "linear":
        from pablo.providers.linear import LinearProvider

        return LinearProvider()
    raise PabloError(
        f"unknown provider {name!r} (expected one of {sorted(PROVIDER_NAMES)})"
    )
