## Language

- All prose you produce for the user in this session — analyses,
  summaries, fix plans, explanations, questions — is written in
  **English**, regardless of the language of the issue, ticket,
  review comments, QA feedback, or the user's own messages.
- You may quote source text verbatim (ticket excerpts, reviewer or QA
  comments, commit messages) in its original language when quoting is
  the most faithful way to convey it, with your own English text
  around it.

## GitHub etiquette — never write without explicit approval

- Never post, reply, or edit anything on GitHub on your own initiative:
  no PR comments, no replies to review threads, no review submissions,
  no edits to a PR's title or body, no issue comments.
- When a reply or a rewritten PR description is genuinely useful, write
  the proposed text as a **draft in your output** and stop there; the
  actual posting/editing is done by the user. Only perform the write
  itself if the user explicitly approves *that* text in the session.
- Printing the draft is not the write: the user (or a deliberately
  invoked command such as `/pablo-commit-and-pr`) owns the actual
  posting; and a passive "if you need to, go ahead" does NOT count as
  approval — the user must confirm the specific write in the session
  before you act.

## Git writes — commit and push only on explicit demand

- Never `git commit` and never `git push` on your own initiative — not
  even when executing a plan that was decided, not even as the final
  step after the work is done: completing a plan is not a mandate to
  commit or push.
- The only legitimate triggers are an explicit user demand made in the
  session for that specific commit/push, or the deliberate invocation of
  the `/pablo-commit-and-pr` command — the sole sanctioned path for
  committing and pushing task work. A passive "go ahead" is not an
  explicit demand: the user must name the commit/push themselves or
  invoke the command.
- When you implement or apply changes, stop at a clean, uncommitted
  working tree, say so in your summary, and suggest
  `/pablo-commit-and-pr` to move the work forward.
- Sole exception: an agent whose assigned task is itself a git history
  operation — the rebase-conflict-resolver finishing an aborted rebase —
  performs exactly the git writes its task mandates (replaying existing
  commits, pushing per its own Push rule) and never creates new commits
  of its own beyond that.
