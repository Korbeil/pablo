# Provider access: CLI-first, no tokens (Jira via MCP)

PABLO talks to external services through each service's CLI — with one
deliberate exception, **Jira, which goes through the Atlassian MCP
server** (spec amended 2026-07-26). No raw API calls, no stored tokens
anywhere (there is no secrets section in the config and none should be
added). Each CLI/bridge manages its own authentication; when one isn't
ready, PABLO surfaces **its own error/instructions** verbatim.

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

## Jira — Atlassian MCP

`https://mcp.atlassian.com/v1/mcp`, reached via the
[`mcp-remote`](https://www.npmjs.com/package/mcp-remote) stdio bridge;
client in `src/pablo/mcpclient.py`.

- tools used: `getAccessibleAtlassianResources` (cloud id — matched
  against the optional `issue_tracker.site` config, e.g.
  `acme.atlassian.net`; without it, the account's first site),
  `getJiraIssue`, `searchJiraIssuesUsingJql`
  (`project = <KEY> AND assignee = currentUser()`), `atlassianUserInfo`
  (doctor).
- **one-time auth**: `npx -y mcp-remote https://mcp.atlassian.com/v1/mcp`
  — completes the Atlassian browser login; tokens are cached by the
  bridge under `~/.mcp-auth/` (PABLO stores nothing). Re-run the same
  command if Atlassian ever revokes the grant.
- **Node resolution — via nvm + `.nvmrc`**: mcp-remote needs Node ≥ 18,
  but the systemd user manager's PATH carries nvm's `default` Node,
  which is deliberately old (16.17, kept for legacy projects). PABLO
  therefore pins its own Node in the repo's **`.nvmrc`** (currently
  `24`) and resolves it through nvm itself —
  `nvm which $(cat .nvmrc)` with `nvm.sh` sourced from
  `$NVM_DIR`/`~/.nvm` — then prepends that bin dir to the bridge's
  PATH (npx's `env node` shebang). Plain PATH lookup is only the
  fallback when nvm isn't installed. Bump `.nvmrc` to change the
  version; the nvm `default` alias is never touched.
- failure signal: changelog transition timestamps when `getJiraIssue`
  responses carry a changelog (**probed live 2026-07-26: they don't** —
  top-level keys are expand/fields/id/key/self — so the fallback below
  is the active path); otherwise the poller's
  **observed-transition fallback** — it records `last_seen_issue_status`
  on the task each poll, and the issue *entering*
  `testing.failure_signal` between two polls counts as one event
  (stamped at observation time; granularity =
  `state_polling.interval_minutes`; seeded at `needs-testing` entry so a
  stale status never fires). Interactive agents use the session's
  Atlassian MCP tools directly.
- **retry on transient failures**: `call_tool_with_retry()`
  (`src/pablo/mcpclient.py`) retries up to 3 times with a short backoff
  when a bridge session fails to connect at all (connect timeout, DNS
  failure, bridge exiting before responding to `initialize`) — real auth
  or tool errors are never retried, they surface immediately.

## Linear — `linear`

[schpet/linear-cli](https://github.com/schpet/linear-cli), the chosen
Linear CLI: `linear issue view <KEY> --json`,
`linear issue list --assignee <id> --json`; failure signal = history
transitions into `testing.failure_signal`; auth check:
`linear auth status`. ⚠️ Not yet installed on this machine — the exact
subcommand spellings above are the provider's assumptions; verify against
`linear --help` on first install and adjust
`src/pablo/providers/linear.py` if they differ.

## CLI preflight — `pablo doctor` / `/pablo-doctor`

A **Python** check (`src/pablo/doctor.py`; the spec originally said bash
and was amended 2026-07-26). Required set is derived from the configured
projects: `gh`, `opencode`, `orca` always (`opencode`/`orca` are PABLO
additions to the spec's set — the agent-runner path needs them);
**`jira-mcp`** / `linear` only when some project uses that provider. CLIs
are checked for **installed** (PATH) and **authenticated/ready** (their
own status/whoami command). The `jira-mcp` check verifies `npx` exists,
then **fails fast if `~/.mcp-auth` has no cached tokens** (it never
spawns the bridge un-authenticated — that would open a browser / hang
under systemd; the ❌ line carries the one-time auth command), and only
then calls `atlassianUserInfo` through the bridge. Per-check ✅/❌ lines,
non-zero exit — the dispatcher runs the same check as its fail-fast
guard. Probes are capped at 30s (`orca` has been observed hanging when
invoked outside an interactive session), and in the **dispatcher**
preflight an `orca` failure is soft — a warning, not an abort — because
agent runs fall back to headless `opencode run`; interactively,
`pablo doctor` still reports it as ❌.
