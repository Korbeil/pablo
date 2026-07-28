import json
from datetime import datetime, timezone

from pablo import ghpr

ANCHOR = datetime(2026, 7, 20, 12, 0, tzinfo=timezone.utc)


def test_prs_for_branches_matches_multiple_branches_from_one_call(monkeypatch):
    calls = []

    def fake_run_cli(argv, *, check=True, timeout=None):
        calls.append(argv)
        return json.dumps(
            [
                {
                    "number": 7, "title": "Fix callbacks", "state": "OPEN",
                    "isDraft": False, "mergedAt": None, "url": "u7",
                    "headRefName": "wk-45",
                },
                {
                    "number": 8, "title": "Add exports", "state": "MERGED",
                    "isDraft": False, "mergedAt": "2026-07-20T00:00:00Z", "url": "u8",
                    "headRefName": "wk-46",
                },
                {
                    "number": 9, "title": "Unrelated", "state": "OPEN",
                    "isDraft": False, "mergedAt": None, "url": "u9",
                    "headRefName": "some-other-branch",
                },
            ]
        )

    monkeypatch.setattr(ghpr, "run_cli", fake_run_cli)
    result = ghpr.prs_for_branches("acme/wallet-kit", ["wk-45", "wk-46", "wk-47"])

    assert len(calls) == 1  # one gh call for all branches
    assert set(result) == {"wk-45", "wk-46"}  # wk-47 has no matching PR
    assert result["wk-45"].number == 7
    assert result["wk-46"].state == "MERGED"


def test_prs_for_branches_empty_list_makes_no_call(monkeypatch):
    def boom(*a, **k):
        raise AssertionError("must not be called for an empty branch list")

    monkeypatch.setattr(ghpr, "run_cli", boom)
    assert ghpr.prs_for_branches("acme/wallet-kit", []) == {}


def review(login: str, state: str, when: str, typename: str = "User"):
    return {
        "author": {"login": login, "__typename": typename},
        "state": state,
        "submittedAt": when,
    }


def test_ci_red_on_failure():
    rollup = [
        {"status": "COMPLETED", "conclusion": "SUCCESS"},
        {"status": "COMPLETED", "conclusion": "FAILURE"},
    ]
    assert ghpr.evaluate_ci(rollup) == "red"


def test_ci_red_wins_over_pending():
    rollup = [
        {"status": "IN_PROGRESS", "conclusion": None},
        {"status": "COMPLETED", "conclusion": "FAILURE"},
    ]
    assert ghpr.evaluate_ci(rollup) == "red"


def test_ci_pending_not_green():
    rollup = [
        {"status": "COMPLETED", "conclusion": "SUCCESS"},
        {"status": "QUEUED", "conclusion": None},
    ]
    assert ghpr.evaluate_ci(rollup) == "pending"


def test_ci_green_when_all_pass_or_skipped():
    rollup = [
        {"status": "COMPLETED", "conclusion": "SUCCESS"},
        {"status": "COMPLETED", "conclusion": "SKIPPED"},
        {"status": "COMPLETED", "conclusion": "NEUTRAL"},
    ]
    assert ghpr.evaluate_ci(rollup) == "green"


def test_ci_green_when_no_checks():
    assert ghpr.evaluate_ci([]) == "green"


def test_ci_statuscontext_shape():
    # statusCheckRollup mixes CheckRun and StatusContext entries
    rollup = [{"state": "SUCCESS"}, {"state": "FAILURE"}]
    assert ghpr.evaluate_ci(rollup) == "red"


def test_ci_ignores_approval_check_by_name():
    # A CircleCI approval job left "on hold" reports action_required forever
    # — it must not be mistaken for a real CI failure.
    rollup = [
        {"status": "COMPLETED", "conclusion": "SUCCESS"},
        {"status": "COMPLETED", "conclusion": "ACTION_REQUIRED", "name": "hold-for-approval"},
    ]
    assert ghpr.evaluate_ci(rollup) == "red"  # not ignored by default
    assert ghpr.evaluate_ci(rollup, ignore_checks=["approval"]) == "green"


def test_ci_ignore_checks_matches_workflow_name_and_context():
    rollup = [
        {"status": "COMPLETED", "conclusion": "ACTION_REQUIRED", "workflowName": "Approval Gate"},
        {"state": "PENDING", "context": "ci/circleci: approval-job"},
    ]
    assert ghpr.evaluate_ci(rollup, ignore_checks=["approval"]) == "green"


def test_ci_ignore_checks_real_circleci_approval_shape():
    # Actual shape from an acme/pim PR: CircleCI approval gates surface as
    # StatusContext entries stuck at state PENDING (nobody clicks approve),
    # which without filtering leaves evaluate_ci returning "pending"
    # forever — and pending never triggers a state transition.
    rollup = [
        {"__typename": "CheckRun", "status": "COMPLETED", "conclusion": "SUCCESS", "name": "Labeler"},
        {"__typename": "StatusContext", "context": "ci/circleci: tests_workflow/deploy-code-approval-ppr", "state": "PENDING"},
        {"__typename": "StatusContext", "context": "ci/circleci: tests_workflow/deploy-code-approval-prod", "state": "PENDING"},
        {"__typename": "StatusContext", "context": "ci/circleci: build", "state": "SUCCESS"},
        {"__typename": "StatusContext", "context": "ci/circleci: tests", "state": "SUCCESS"},
    ]
    assert ghpr.evaluate_ci(rollup) == "pending"
    assert ghpr.evaluate_ci(rollup, ignore_checks=["approval"]) == "green"


def test_ci_ignore_checks_case_insensitive_and_unmatched_still_evaluated():
    rollup = [
        {"status": "COMPLETED", "conclusion": "ACTION_REQUIRED", "name": "APPROVAL-hold"},
        {"status": "COMPLETED", "conclusion": "FAILURE", "name": "unit-tests"},
    ]
    assert ghpr.evaluate_ci(rollup, ignore_checks=["Approval"]) == "red"


def test_reviews_exclude_author():
    reviews = [review("me", "CHANGES_REQUESTED", "2026-07-21T10:00:00Z")]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        is None
    )


def test_reviews_exclude_bot_unless_whitelisted():
    reviews = [
        review("sonar[bot]", "COMMENTED", "2026-07-21T10:00:00Z", typename="Bot")
    ]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        is None
    )
    assert (
        ghpr.evaluate_reviews(
            reviews, anchor=ANCHOR, author="me", bot_whitelist=["sonar[bot]"]
        )
        == "changes"
    )


def test_reviews_before_anchor_ignored():
    reviews = [review("alice", "CHANGES_REQUESTED", "2026-07-19T10:00:00Z")]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        is None
    )


def test_latest_per_reviewer_wins():
    reviews = [
        review("alice", "CHANGES_REQUESTED", "2026-07-21T10:00:00Z"),
        review("alice", "APPROVED", "2026-07-22T10:00:00Z"),
    ]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        == "approved"
    )


def test_mixed_verdicts_changes_wins():
    reviews = [
        review("alice", "APPROVED", "2026-07-21T10:00:00Z"),
        review("bob", "CHANGES_REQUESTED", "2026-07-21T11:00:00Z"),
    ]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        == "changes"
    )


def test_comment_review_counts_as_changes():
    reviews = [review("alice", "COMMENTED", "2026-07-21T10:00:00Z")]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        == "changes"
    )


def test_dismissed_reviews_ignored():
    reviews = [review("alice", "DISMISSED", "2026-07-21T10:00:00Z")]
    assert (
        ghpr.evaluate_reviews(reviews, anchor=ANCHOR, author="me", bot_whitelist=[])
        is None
    )


def test_no_reviews_returns_none():
    assert ghpr.evaluate_reviews([], anchor=ANCHOR, author="me", bot_whitelist=[]) is None
