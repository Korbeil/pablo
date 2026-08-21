# Provider access: CLI-first, no tokens

PABLO talks to external services through each service's CLI — no MCP
servers, no raw API calls, no stored tokens anywhere (there is no secrets
section in the config and none should be added). Each CLI manages its own
authentication; when one isn't ready, PABLO surfaces **its own
error/instructions** verbatim.

## GitHub — `gh`

Always required; every project's PR/CI flow goes through GitHub
regardless of tracker:

- issues: `gh issue view/list --json`, URL matching against
  `git remote get-url origin`
- PR lookup: `gh pr list --head <branch> --json number,title,state,isDraft,mergedAt,url`
- CI: `gh pr view <n> --json statusCheckRollup`
- reviews + ready-for-review anchor: `gh api graphql` (PR `reviews`
  nodes; `timelineItems(itemTypes: [READY_FOR_REVIEW_EVENT])`)
- draft/ready toggling: `gh pr ready <n>` / `gh pr ready <n> --undo`
- failure-signal labels: `gh api repos/<o>/<r>/issues/<pr>/events --paginate`
  (`labeled` events)
- auth check: `gh auth status`

## Jira — `acli` (Atlassian CLI)

Atlassian's own [`acli`](https://atlassian.com/cli) CLI; OAuth owned and
cached by acli itself. Provider in `src/Provider/Tracker/Jira.php`;

- issue: `acli jira workitem view <KEY> --json --fields 'summary,status'`
  (default fields are `key,issuetype,summary,status,assignee,description`;
  use `'*all'` for everything acli exposes, including comments).
- assigned issues:
  `acli jira workitem search --jql "project = <KEY> AND assignee = currentUser() ORDER BY updated DESC" --json --limit 50`
  (a list of `{key, fields:{summary,status,...}, ...}`).
- comments: `acli jira workitem comment list --key <KEY> --json`
  (`{"comments": [...], "isLast": bool, ...}` — used by interactive
  agents for QA feedback).
- **one-time auth**: `acli auth login` — completes the Atlassian browser
  login; the grant is shared across `acli jira …` and
  `acli confluence …` (one OAuth account, two scoped probes). Re-run if
  Atlassian ever revokes the grant. PABLO stores nothing.
- **failure signal**: acli does *not* expose a work-item changelog in its
  JSON output (probed live 2026-07-29: the `changelog` field is always
  `null`), so the poller's **observed-transition fallback** is the only
  path — it records `last_seen_issue_status` on the task each poll, and
  the issue *entering* `testing.failure_signal` between two polls counts
  as one event (stamped at observation time; granularity =
  `state_polling.interval_minutes`; seeded at `needs-testing` entry so a
  stale status never fires).
- CLI calls through `providers.run_cli` (≤30s timeout), surfacing acli's
  own error text on failure.

## Confluence — `acli confluence`

Same `acli` CLI, same OAuth grant; the **documentation** path
(`src/Provider/Confluence/Confluence.php`, used by interactive agents when an issue references a wiki page):

- page: `acli confluence page view --id <id> --json --body-format storage`
  → `{id, title, _links:{base,webui}, body:{storage:{value:"<XHTML>"}}}`.
  The body is **Confluence storage-format XHTML** (with `<ac:*>` macros);
  returned verbatim — rendering to Markdown is the caller's job (agents
  tolerate raw XHTML).
- space discovery: `acli confluence space list --json`
  (advisory; the optional `confluence.space` project config scopes agents'
  doc lookups but acli's OAuth grant is the real gatekeeper).
- URL → id: `confluence.match_url` accepts both
  `…/wiki/spaces/<KEY>/pages/<id>[/Title]` and `…/wiki?pageId=<id>`; a
  bare numeric id is passed straight through.
- auth check: `acli confluence auth status` (separate probe from
  `acli jira auth status` so the doctor can report jira-ready vs
  confluence-not-ready distinctly, even though they share one OAuth grant).

## Linear — `linear`

[schpet/linear-cli](https://github.com/schpet/linear-cli), the chosen
Linear CLI: `linear issue view <KEY> --json`,
`linear issue list --assignee <id> --json`; failure signal = history
transitions into `testing.failure_signal`; auth check:
`linear auth status`. ⚠️ Not yet installed on this machine — the exact
subcommand spellings above are the provider's assumptions; verify against
`linear --help` on first install and adjust
`src/Provider/Tracker/Linear.php` if they differ.

## CLI preflight — `pablo system:doctor`

A preflight check (`src/Doctor.php`). Required set is derived from
the configured projects: `gh`, `opencode`, `orca` always
(`opencode`/`orca` are PABLO additions to the spec's set — the
agent-runner path needs them); **`acli`** only when some project uses the
Jira provider; **`acli-confluence`** additionally when at least one
project configures a `confluence.space`; `linear` only when some project
uses that provider. CLIs are checked for **installed** (PATH; the probe's
first argv element is the binary — `acli` appears in two probes) and
**authenticated/ready** (each CLI's own status command). Per-check ✅/❌
lines, non-zero exit — the dispatcher runs the same check as its
fail-fast guard for the **hard** requirements. Probes are capped at 30s
(`orca` has been observed hanging when invoked outside an interactive
session), and in the **dispatcher** the provider CLIs (`acli`,
`acli-confluence`, `linear`, `orca`) are **soft** — a failure warns and
continues, degrading only the projects that use that provider (they fail
per-project at runtime), never aborting the whole run; only `gh` and
`opencode` are hard (a failure there aborts). Interactively,
`pablo system:doctor` still reports every failing check as ❌.
It also compares each generated opencode agent under
`~/.config/opencode/agents/` against a fresh in-memory render: ✅ fresh,
⚠️ stale (soft — re-run `pablo system:generate-agents`) and ❌ for missing
files, broken symlinks or files PABLO does not manage.
