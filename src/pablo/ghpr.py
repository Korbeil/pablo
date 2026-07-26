"""GitHub PR plumbing via the ``gh`` CLI: CI status, review evaluation,
draft/ready toggling, merge detection.

Review-evaluation rules (spec, "Post-draft state transitions"):
- the PR author's own reviews never count;
- bot reviews are ignored unless whitelisted in ``review.bot_whitelist``;
- only reviews submitted after the PR was last marked ready on GitHub count
  (GitHub's own ``ready_for_review`` timeline event is the anchor — PABLO's
  internal transitions never move it);
- latest review per reviewer wins; changes-requested/comment beats approval;
- GitHub's ``reviewDecision`` is never used.
"""

from __future__ import annotations

import json
from dataclasses import dataclass
from datetime import datetime

from pablo.providers import parse_ts, run_cli

CI_FAILURE_CONCLUSIONS = {
    "FAILURE",
    "TIMED_OUT",
    "CANCELLED",
    "ACTION_REQUIRED",
    "STARTUP_FAILURE",
    "ERROR",
}


@dataclass
class PrInfo:
    number: int
    title: str
    state: str  # OPEN | CLOSED | MERGED
    is_draft: bool
    url: str
    merged_at: str | None


def pr_for_branch(repo_slug: str, branch: str) -> PrInfo | None:
    out = run_cli(
        ["gh", "pr", "list", "--repo", repo_slug, "--head", branch,
         "--state", "all", "--json", "number,title,state,isDraft,mergedAt,url",
         "--limit", "1"]
    )
    items = json.loads(out)
    if not items:
        return None
    item = items[0]
    return PrInfo(
        number=item["number"],
        title=item["title"],
        state=item["state"],
        is_draft=item["isDraft"],
        url=item["url"],
        merged_at=item.get("mergedAt"),
    )


def evaluate_ci(rollup: list[dict]) -> str:
    """"green" | "red" | "pending" from a statusCheckRollup list.

    Handles both CheckRun entries (status/conclusion) and StatusContext
    entries (state). No checks at all counts as green — otherwise a project
    without CI could never leave draft.
    """
    pending = False
    for check in rollup:
        if "state" in check:  # StatusContext
            state = check["state"]
            if state in {"FAILURE", "ERROR"}:
                return "red"
            if state in {"PENDING", "EXPECTED"}:
                pending = True
            continue
        if check.get("status") != "COMPLETED":
            if (check.get("conclusion") or "").upper() in CI_FAILURE_CONCLUSIONS:
                return "red"
            pending = True
            continue
        if (check.get("conclusion") or "").upper() in CI_FAILURE_CONCLUSIONS:
            return "red"
    return "pending" if pending else "green"


def ci_status(repo_slug: str, pr_number: int) -> str:
    out = run_cli(
        ["gh", "pr", "view", str(pr_number), "--repo", repo_slug,
         "--json", "statusCheckRollup"]
    )
    return evaluate_ci(json.loads(out).get("statusCheckRollup") or [])


_TIMELINE_QUERY = """
query($owner: String!, $repo: String!, $pr: Int!) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $pr) {
      createdAt
      timelineItems(itemTypes: [READY_FOR_REVIEW_EVENT], last: 1) {
        nodes { ... on ReadyForReviewEvent { createdAt } }
      }
    }
  }
}
"""


def ready_anchor(repo_slug: str, pr_number: int) -> datetime:
    """When the PR last became ready for review on GitHub.

    Falls back to the PR's createdAt for PRs that were never drafts.
    """
    owner, repo = repo_slug.split("/", 1)
    out = run_cli(
        ["gh", "api", "graphql",
         "-f", f"query={_TIMELINE_QUERY}",
         "-F", f"owner={owner}", "-F", f"repo={repo}", "-F", f"pr={pr_number}"]
    )
    pr = json.loads(out)["data"]["repository"]["pullRequest"]
    nodes = [n for n in pr["timelineItems"]["nodes"] if n]
    if nodes:
        return parse_ts(nodes[-1]["createdAt"])
    return parse_ts(pr["createdAt"])


_REVIEWS_QUERY = """
query($owner: String!, $repo: String!, $pr: Int!) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $pr) {
      author { login }
      reviews(first: 100) {
        nodes {
          author { login __typename }
          state
          submittedAt
        }
      }
    }
  }
}
"""


def fetch_reviews(repo_slug: str, pr_number: int) -> tuple[str, list[dict]]:
    """(pr_author_login, raw review nodes)."""
    owner, repo = repo_slug.split("/", 1)
    out = run_cli(
        ["gh", "api", "graphql",
         "-f", f"query={_REVIEWS_QUERY}",
         "-F", f"owner={owner}", "-F", f"repo={repo}", "-F", f"pr={pr_number}"]
    )
    pr = json.loads(out)["data"]["repository"]["pullRequest"]
    return pr["author"]["login"], [n for n in pr["reviews"]["nodes"] if n]


def evaluate_reviews(
    reviews: list[dict],
    *,
    anchor: datetime,
    author: str,
    bot_whitelist: list[str],
) -> str | None:
    """"approved" | "changes" | None, applying the spec's filtering rules."""
    latest: dict[str, dict] = {}
    for node in reviews:
        review_author = (node.get("author") or {}).get("login")
        if not review_author or review_author == author:
            continue
        is_bot = (
            (node.get("author") or {}).get("__typename") == "Bot"
            or review_author.endswith("[bot]")
        )
        if is_bot and review_author not in bot_whitelist:
            continue
        if node.get("state") not in {"APPROVED", "CHANGES_REQUESTED", "COMMENTED"}:
            continue
        submitted = node.get("submittedAt")
        if not submitted or parse_ts(submitted) <= anchor:
            continue
        current = latest.get(review_author)
        if current is None or parse_ts(submitted) > parse_ts(current["submittedAt"]):
            latest[review_author] = node
    verdicts = {node["state"] for node in latest.values()}
    if {"CHANGES_REQUESTED", "COMMENTED"} & verdicts:
        return "changes"
    if "APPROVED" in verdicts:
        return "approved"
    return None


def mark_ready(repo_slug: str, pr_number: int) -> None:
    run_cli(["gh", "pr", "ready", str(pr_number), "--repo", repo_slug])


def mark_draft(repo_slug: str, pr_number: int) -> None:
    run_cli(["gh", "pr", "ready", str(pr_number), "--repo", repo_slug, "--undo"])


def is_merged(repo_slug: str, pr_number: int) -> bool:
    out = run_cli(
        ["gh", "pr", "view", str(pr_number), "--repo", repo_slug,
         "--json", "state,mergedAt"]
    )
    data = json.loads(out)
    return data["state"] == "MERGED" or bool(data.get("mergedAt"))
