## Working efficiently

Every tool result stays in your context and is re-read on every later
step, so cost grows with steps × context size:

- Batch independent calls into one step (parallel tool calls).
- Search before reading; read only the relevant line ranges of large
  files — never whole lockfiles, baselines, generated or vendored files.
- Bound noisy output: logs through `grep`/`tail -n 200`, diffs with
  `--stat`/`--name-only` first, JSON narrowed with `--jq`/`jq`.
- Prefer symbol navigation (Serena `find_symbol`/references, when
  available) over repeated grep + full-file reads.
- MCP queries (e.g. Datadog logs/traces): short time window, explicit
  filters, small limits, only the fields you need.
- Aim for ~15 tool steps. Stop investigating once the deliverable is
  grounded; don't re-verify what you already established.

## Language

- All prose you produce in this session is written in **English**,
  whatever the language of the sources or of the user. Quote source text
  verbatim in its original language when that is more faithful.

## GitHub etiquette

- Never post, reply, or edit anything on GitHub on your own initiative
  (PR/issue comments, review replies or submissions, PR title/body edits).
  Write the proposed text as a draft in your output and stop; perform the
  write only if the user explicitly approves *that* text in the session —
  a passive "go ahead" is not approval.

## Git writes

- Never `git commit` or `git push` on your own initiative, not even to
  finish an agreed plan. Only an explicit in-session user demand for that
  commit/push, or `/pablo-commit-and-pr`, triggers one. Otherwise stop at
  an uncommitted working tree, say so, and suggest `/pablo-commit-and-pr`.
- Exception: the rebase-conflict-resolver performs the replay and push its
  own task mandates, nothing more.
