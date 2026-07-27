---
description: Liste Slack des PR en attente de review, groupées par projet
subtask: true
---

## Context (auto-injected)

!`pablo queue waiting-review`

## Your task

The JSON array above lists every PABLO task currently in the
`waiting-review` (👀) state, across all configured projects, each with its
project name, branch, linked issue (if any), and PR (`number`, `title`,
`url`, `is_draft`), fetched live from GitHub.

Turn it into a **Slack-formatted (mrkdwn) message**, grouped by `project`:

```
*<project>*
• <<pr.url>|#<pr.number> <pr.title>>
```

Rules:
- One block per project, in the order projects first appear in the array.
- Within a project, one bullet per task, in array order.
- Use Slack's link syntax `<url|text>` for the PR link — never a bare
  Markdown link.
- If a task has no `pr` (no PR yet), skip that bullet — it's not
  something a reviewer can act on. If that empties a project's whole
  group, omit the group.
- No commentary, no headers, no summary before or after the list — the
  output must be paste-ready into Slack as-is.
- If the array is empty, or nothing has a PR, output exactly:
  `No PRs waiting for review right now 🎉`
- If the command above failed (not a JSON array), output its error
  message instead.
