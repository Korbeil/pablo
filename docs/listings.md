# Listings

**`/pablo-issues [project]`** — issues assigned to me (via
`issue_tracker.identity`), one table per project: issue key + title,
tracker status, related PR (matched via the branch convention incl.
`-2`/`-3` variants), local branch. Cells show `-` when nothing matches —
no guessing. Read-only.

**`/pablo-tasks`** — active tasks, columns in order: ① branch (project)
② PABLO state ③ linked issue + title, or the ≤5-word summary for prompt
tasks ④ the tracker's own status (`N/A` for prompt tasks) ⑤ related PR:
📬 open · 📪 draft · ✅ merged (✅ only appears when auto-close is
deferred behind running agents — normally a merged task is already gone
from the listing) ⑥ number of agent sessions on the worktree ⑦ their
activity, e.g. `🏃 1 · ⏳ 1`. State legend: 🔨 in-progress · ⏸️ waiting ·
📝 draft · 🔴 ci-red · 👀 waiting-review · 🧪 needs-testing ·
🔁 request-changes · ❌ testing-failed.
