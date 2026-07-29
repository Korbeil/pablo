# Listings

**`/pablo-issues [project]`** — issues assigned to me (via
`issue_tracker.identity`), one table per project: issue key + title,
tracker status, related PR (matched via the branch convention incl.
`-2`/`-3` variants), local branch. Cells show `-` when nothing matches —
no guessing. Read-only.

**`/pablo-tasks`** — active tasks, split into two tables (each sorted
by state, in this order: ❌ testing-failed · 🧪 needs-testing ·
🔁 request-changes · 👀 waiting-review · 👀 ready-to-review · 🔴 ci-red
· 📝 draft · ⏸️ waiting · 🔨 in-progress; ties keep store insertion
order):

1. **⏳ Waiting for feedback** — tasks in 🔨 in-progress, 🔴 ci-red,
   🔁 request-changes, or ❌ testing-failed that also have at least one
   agent session in the waiting-on-feedback state (activity cell contains
   `⏳`). Tasks in any other state always appear in "Other tasks",
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
`🏃 1 · ⏳ 1`. State legend matches the sort order above.
