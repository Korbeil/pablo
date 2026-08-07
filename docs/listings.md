# Listings

**`/pablo-issues [project]`** — issues assigned to me (via
`issue_tracker.identity`), one table per project: issue key + title,
tracker status, related PR (matched via the branch convention incl.
`-2`/`-3` variants), local branch. Cells show `-` when nothing matches —
no guessing. Read-only.

**`/pablo-tasks`** — active tasks, split into two tables (each sorted
by state, in this order: 🚨 testing-failed · 🧪 needs-testing ·
🔁 request-changes · 👀 waiting-review · 👀 ready-to-review · 🔴 ci-red
· 📝 draft · ⏸️ waiting · 🔨 in-progress; ties keep store insertion
order):

1. **💭 Waiting for feedback** — tasks in 🔨 in-progress, 🔴 ci-red,
   🔁 request-changes, or 🚨 testing-failed that also have at least one
   agent session in the waiting-on-feedback state (activity cell contains
   `💭`). Tasks in any other state always appear in "Other tasks",
   regardless of agent activity.
2. **Other tasks** — everything else. The "Other tasks" header is
   omitted when there are no waiting-feedback tasks, preserving the
   single-table look for the common case.

Columns in order: ① branch (project) ② PABLO state ③ linked issue +
title, or the ≤5-word summary for prompt tasks ④ the tracker's own
status (`N/A` for prompt tasks) ⑤ related PR: 📬 open · 📪 draft ·
✅ merged (✅ only appears when auto-close is deferred behind running
agents — normally a merged task is already gone from the listing) ⑥
number of agent sessions on the worktree ⑦ their activity, e.g.
`🏃 1 · 💭 1`. State legend matches the sort order above.

Both listings are rendered with the Symfony Console **Table** component
(bordered grid). Columns are padded to the widest visible cell in each
column; note that Table measures string length rather than terminal
display width, so wide emoji (🏃💭🧪) may align slightly differently
than a hand-tuned width calculator would.

**`pablo show:prs [waiting-review|needs-testing]`** — paste-ready Slack
(mrkdwn) list of PRs awaiting review and/or QA, run from a shell (not an
OpenCode command). With no argument it prints both queues separated by a
`―――― review above · QA below ――――` divider so each block can be
copied into its own Slack channel; pass `waiting-review` or
`needs-testing` to print just one block.

Each block is grouped by project (one `*<project>*` header per group),
one bullet per task that has a PR, formatted as the bare PR URL followed
by the issue key and title (or `#<n> <title>` when the task has no
issue) — Slack unfurls the URL itself. Tasks without a PR are skipped; a project whose
tasks all lack PRs is omitted. An empty queue (or one with no PRs)
prints `No PRs waiting for review right now 🎉` / `Nothing needs testing
right now 🎉`. No headers, no commentary — the output is paste-ready
as-is. Replaces the former `/pablo-waiting-review` and
`/pablo-needs-testing` OpenCode commands (which relied on an LLM to
format the same JSON); the formatting is now deterministic in the engine.

## Web equivalent

The same three listings are also rendered as a local web dashboard —
`pablo web`, see [docs/dashboard.md](dashboard.md). It reads the poller's
display cache rather than fetching live, and its "needs attention" split
is deliberately wider than the terminal's "💭 Waiting for feedback"
section.
