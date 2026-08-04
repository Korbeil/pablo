"""Task and issue records, plus the canonical state-name constants.

The task-record JSON contract is documented in @docs/state-machine.md
("Task state storage"). One task == one worktree created via /pablo-start.
"""

from __future__ import annotations

from dataclasses import dataclass, field
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

IN_PROGRESS = "in-progress"
WAITING = "waiting"
DRAFT = "draft"
CI_RED = "ci-red"
READY_TO_REVIEW = "ready-to-review"
WAITING_REVIEW = "waiting-review"
NEEDS_TESTING = "needs-testing"
REQUEST_CHANGES = "request-changes"
TESTING_FAILED = "testing-failed"

ALL_STATES = (
    IN_PROGRESS,
    WAITING,
    DRAFT,
    CI_RED,
    READY_TO_REVIEW,
    WAITING_REVIEW,
    NEEDS_TESTING,
    REQUEST_CHANGES,
    TESTING_FAILED,
)


def utcnow() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


@dataclass
class Issue:
    provider: str
    key: str
    url: str
    title: str
    project_key: str | None = None
    status: str | None = None  # the tracker's own status, e.g. "In Progress"

    def to_json(self) -> dict[str, Any]:
        return {
            "provider": self.provider,
            "key": self.key,
            "url": self.url,
            "title": self.title,
            "project_key": self.project_key,
            "status": self.status,
        }

    @classmethod
    def from_json(cls, data: dict[str, Any]) -> "Issue":
        return cls(
            provider=data["provider"],
            key=data["key"],
            url=data["url"],
            title=data["title"],
            project_key=data.get("project_key"),
            status=data.get("status"),
        )


@dataclass
class Task:
    project: str
    branch: str
    worktree_path: Path
    state: str
    issue: Issue | None = None
    summary: str | None = None
    prompt: str | None = None
    state_before_waiting: str | None = None
    task_analyst_ran: bool = False
    startup_script_ran: bool = False
    # Generic per-label launch tracking: label -> {"launched_at": str|None,
    # "attempts": int}. Lets the poller self-heal ANY agent-launching state
    # (in-progress/ci-red/request-changes/testing-failed) when a cold-worktree
    # Orca ``terminal create`` hang leaves the label with no live session.
    # The ``*_ran`` flags above are the distinct "don't re-fire on a manual
    # /pablo-state re-entry" guard for in-progress; this dict records the
    # last attempt so the poller can decide whether to re-fire (poller.py).
    agent_launches: dict[str, dict] = field(default_factory=dict)
    state_entered_at: str = field(default_factory=utcnow)
    needs_testing_entered_at: str | None = None
    last_handled_signal_at: str | None = None
    last_seen_issue_status: str | None = None
    ci_ignored: bool = False
    pr_number: int | None = None
    merged: bool = False
    created_at: str = field(default_factory=utcnow)
    updated_at: str = field(default_factory=utcnow)
    # Display cache written by the poller (poller.py) on its regular
    # cadence, so `pablo tasks` can render instantly by reading these
    # instead of re-fetching Jira/gh/orca live on every invocation. None
    # means "never polled yet" — listing.py falls back to a live fetch.
    cached_tracker_status: str | None = None
    cached_pr_state: str | None = None
    cached_agent_count: int | None = None
    cached_agent_activity: str | None = None
    cached_at: str | None = None

    def to_json(self) -> dict[str, Any]:
        return {
            "project": self.project,
            "branch": self.branch,
            "worktree_path": str(self.worktree_path),
            "state": self.state,
            "state_entered_at": self.state_entered_at,
            "issue": self.issue.to_json() if self.issue else None,
            "summary": self.summary,
            "prompt": self.prompt,
            "state_before_waiting": self.state_before_waiting,
            "task_analyst_ran": self.task_analyst_ran,
            "startup_script_ran": self.startup_script_ran,
            "agent_launches": self.agent_launches,
            "needs_testing_entered_at": self.needs_testing_entered_at,
            "last_handled_signal_at": self.last_handled_signal_at,
            "last_seen_issue_status": self.last_seen_issue_status,
            "ci_ignored": self.ci_ignored,
            "pr_number": self.pr_number,
            "merged": self.merged,
            "created_at": self.created_at,
            "updated_at": self.updated_at,
            "cached_tracker_status": self.cached_tracker_status,
            "cached_pr_state": self.cached_pr_state,
            "cached_agent_count": self.cached_agent_count,
            "cached_agent_activity": self.cached_agent_activity,
            "cached_at": self.cached_at,
        }

    @classmethod
    def from_json(cls, data: dict[str, Any]) -> "Task":
        issue = data.get("issue")
        return cls(
            project=data["project"],
            branch=data["branch"],
            worktree_path=Path(data["worktree_path"]),
            state=data["state"],
            issue=Issue.from_json(issue) if issue else None,
            summary=data.get("summary"),
            prompt=data.get("prompt"),
            state_before_waiting=data.get("state_before_waiting"),
            task_analyst_ran=data.get("task_analyst_ran", False),
            startup_script_ran=data.get("startup_script_ran", False),
            # Tolerate the supersed slow fields (pre-generalization) so a
            # live task record loads cleanly; they're simply ignored.
            agent_launches=data.get("agent_launches", {}),
            state_entered_at=data.get("state_entered_at", utcnow()),
            needs_testing_entered_at=data.get("needs_testing_entered_at"),
            last_handled_signal_at=data.get("last_handled_signal_at"),
            last_seen_issue_status=data.get("last_seen_issue_status"),
            ci_ignored=data.get("ci_ignored", False),
            pr_number=data.get("pr_number"),
            merged=data.get("merged", False),
            created_at=data.get("created_at", utcnow()),
            updated_at=data.get("updated_at", utcnow()),
            cached_tracker_status=data.get("cached_tracker_status"),
            cached_pr_state=data.get("cached_pr_state"),
            cached_agent_count=data.get("cached_agent_count"),
            cached_agent_activity=data.get("cached_agent_activity"),
            cached_at=data.get("cached_at"),
        )
