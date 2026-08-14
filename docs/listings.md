# Listings

**`pablo task:list`** — active tasks, split into two tables (each sorted
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

## Web equivalent

The dashboard at `pablo web` renders the same task listing from the
poller's display cache — see [docs/dashboard.md](dashboard.md).
