# Dashboard

A single-page, localhost-only web view of the same state
`pablo task:list` / `pablo sync:log` print. Twig +
[Symfony UX](https://ux.symfony.com/) (Twig Components, Live Components,
Icons) on [Bulma](https://bulma.io/). No Docker, no Node, no build step.

```bash
pablo web                  # → http://127.0.0.1:8321
pablo web --port 9000 --open
```

To keep the dashboard up as a background service instead of a foreground
`pablo web`, install with `./bin/install.sh --with-web`. On Linux this
enables a systemd user unit (`pablo-web.service`, auto-restart — logs:
`journalctl --user -u pablo-web.service`); on macOS a launchd agent
`com.pablo.web` (`~/.pablo/logs/web.log`). It's the same server, just
supervised; the page itself is unchanged and strictly read-only.

## Guarantees

**Read-only on render.** Nothing reachable from a page load or a poll
mutates task state, a worktree, a PR, or an issue tracker — the same rule
the rest of PABLO follows. There is one button that writes: the
**new-task modal**, which runs `TaskStarter` (the same core as
`task:start`) only on its explicit "Start task" submit — never on page
load, never on a poll, and it creates a worktree + task + the
task-analyst agent like the CLI always has. Everything else stays
read-only.

**Loopback only.** `pablo web` always binds `127.0.0.1` and that is not
configurable. The page has no authentication and exposes branch names,
issue titles and PR URLs.

**No provider calls on render.** The task tables are built entirely from
the poller-written `DisplayCache` on each task record, so loading or
polling the page never runs `gh`, `orca`, or a tracker CLI. Two
exceptions, both on explicit user action rather than with the page: the
Slack modal (a live `gh` PR lookup per queued task) and the new-task
modal (which may run the `opencode` branch summarizer for a prompt task
before creating the worktree and launching task-analyst). The only
subprocess a page render can make is one local `git remote get-url
origin` per project that has a PR (memoised per request, used to build
PR links, degrades to an unlinked badge).

**Works offline.** Bulma and the Stimulus/LiveComponent JS are vendored
into `app/assets/vendor/` and committed; the Lucide icons are imported
into `app/assets/icons/` and committed, with Iconify on-demand downloads
disabled (`config/packages/ux_icons.php`).

## The page

### Poller countdown

One Bulma progress bar per project, draining to zero as the next poll
approaches.

The subtlety the terminal listing never had to model: the scheduler
fires the dispatcher every `Dispatch::TICK_MINUTES` (5), and the
dispatcher *then* asks whether each project is due. So a project is not
polled at `lastRun + interval` — it is polled at **the first tick at or
after** that instant. `Pablo\Dashboard\PollSchedule` computes that;
`PollScheduleTest` pins the rounding down.

The component re-renders every 15s; the `countdown` Stimulus controller
interpolates locally once a second in between and re-syncs on every
render, so clock drift cannot accumulate.

### Two task tables

**💭 Waiting for feedback** — tasks actually blocked on you:

```
state ∈ {in-progress, ci-red, request-changes, testing-failed}
AND an agent is waiting for input (💭)
AND no agent is currently working (no 🏃)
```

**Other tasks** — everything else, including tasks with an agent
currently working on them (`🏃` keeps the activity visible in the
Agents/Activity columns).

Both come from `Listing::isWaitingForFeedback()`, the *same* predicate
the terminal listing splits on, so `pablo tasks` and this page can never
disagree. All tables then sort by `Listing::stateRank()` then
`stateEnteredAt`, also like the CLI.

Two states deliberately never qualify, however long they sit there:
`needs-testing` means review was approved and the ball is with the PO/QA,
and `waiting-review` means it is with reviewers. Neither is your move. An
earlier, wider rule that counted `needs-testing` as "needs attention" put
10 of 11 real tasks in the top table, which carried no signal at all.

Unlike the CLI, the web page keeps both headings even when a section is
empty — a table needs its header, and the waiting empty state reads
"Nothing is waiting on you right now 🎉".

Re-renders every 30s.

### Slack messages

A button opens a modal with the two paste-ready Slack blocks —
review queue and QA queue — each in a textarea with a copy button.

This is the only part that hits the network: `Listing::queueTasks()`
does a live `gh` PR lookup per queued task. It therefore loads on the
click (the button shows a loading state) rather than with the page, and
never polls. "Refresh" forces a new lookup.

Copying uses `navigator.clipboard`, which needs a secure context —
`http://127.0.0.1` counts as one, so it works without TLS. There is an
`execCommand` fallback for anything that doesn't.

### New task

A button in the header opens a modal that starts a task — the same
`TaskStarter` service the `task:start` CLI wraps, so the two surfaces
cannot drift. Two modes:

* **Issue link** — an issue URL (`https://github.com/.../issues/123`) or
  an issue key (`ABC-123`) in a textarea. No project needed — the starter
  scans every configured project.
* **Prompt** — a textarea for long prompts plus a project dropdown fed
  from the configured projects. The branch name comes from the AI
  summary of the prompt, so the submit button can spin for tens of
  seconds before the task appears in the tables.

Errors (unmatched URL, missing project) render in the modal; a start
renders the outcome ("Started WK-123 ... worktree ... task-analyst is
running") and closes the modal; a second start of the same issue shows
"reusing it" like the CLI. It is the page's only write surface — see
the guarantees above for why and how that's bounded.

### Last sync

One card per project showing the last sync/rebase session
(`~/.pablo/logs/rebase-last-<project>.json`), with a coloured chip per
branch, behind/ahead counts, conflicting files, and the
`opencode -s <handle>` line when a rebase-conflict-resolver agent was
launched. Sync overwrites this file each run, so there is no history —
only "what the last sync did".

## The analytics page (`/analytics`)

Linked from the task board's header ("Analytics"), with a "Task board"
button back. It shows the same aggregated numbers `pablo stats` prints,
as [UX Chart.js](https://ux.symfony.com/chartjs) graphs: five KPI tiles
(tasks opened/closed, merged %, agent runs, cost) plus six charts — tasks
opened-vs-closed per day, tokens per agent (stacked in/out/cache), cache
hit %, avg runtime, cost doughnut, and state dwell + entries (rework
loops). Two link-tab rows filter the view: `?days=7|30|90|all` for the
window and `?type=` (All/Work/Open-source/Personal) reusing the task
board's project-type predicate — events whose project no longer has a
config can't be attributed to a type, so they show under All only.

The same guarantees apply, and then some: the page reads only the local
JSONL log under `~/.pablo/analytics/` — it cannot touch a git/gh/orca/
tracker CLI even by accident. Numbers come from
`Analytics\AnalyticsAggregator`, the single source of truth shared with
`pablo stats` (same rule as `PrBadge`/`AgentActivity`: terminal and web
cannot drift); `Dashboard\AnalyticsCharts` turns those numbers into Chart
objects. Empty log renders a friendly empty state instead of canvases.

## Architecture

| | |
|---|---|
| `src/Dashboard/Dashboard.php` | the one service the page uses: task board + poll windows, cache-only |
| `src/Dashboard/TaskView.php` | one table row (state presentation, badges, timings) |
| `src/Dashboard/PollSchedule.php`, `PollWindow.php` | stamp reading + tick rounding |
| `src/Dashboard/RebaseLogView.php` | decodes the sync log, maps actions to icon/colour |
| `src/Dashboard/AnalyticsCharts.php` | builds the six `/analytics` Chart objects from aggregator output |
| `src/Domain/PrBadge.php`, `AgentActivity.php` | value objects shared by the terminal and the web renderers |
| `src/Twig/Components/` | `TaskBoard`, `PollProgress`, `SlackModal`, `NewTaskModal` (live); `RebaseLog` (plain) |
| `src/Task/TaskStarter.php`, `StartResult.php` | the `task:start` core, shared by the CLI command and the new-task modal; `IssueMatch.php` lives beside it |
| `src/Controller/DashboardController.php` | the single `GET /` route |
| `src/Controller/AnalyticsController.php` | the `GET /analytics` route |
| `src/Command/System/WebCommand.php` | `pablo web` |

`Listing` still renders the terminal cells, but now over `PrBadge` /
`AgentActivity` rather than building strings inline — so the two
surfaces cannot drift. `Listing`'s existing tests are what prove that
extraction changed no terminal output.

Because the poller caches *rendered* strings (`"📖 open #17035"`,
`"🏃 1 · 💭 1"`), the dashboard parses them back with
`PrBadge::fromDisplay()` / `AgentActivity::fromDisplay()`. Those are our
own output, so the parse is deterministic; `PrBadgeTest` and
`AgentActivityTest` assert every kind round-trips.

## Assets

`app/importmap.php` + AssetMapper. Nothing is fetched at runtime.

```bash
php app/bin/console importmap:install        # re-vendor after changing importmap.php
php app/bin/console ux:icons:import lucide:foo   # add an icon, then commit it
php app/bin/console lint:twig app/templates
```

`app/assets/vendor/` **must stay committed** — `bin/install.sh` never
runs `importmap:install`, so an un-vendored entry means a dashboard with
no JavaScript on a fresh clone and no error anywhere but the browser
console. The repo-root `.gitignore` anchors the Composer vendor pattern
to `/app/vendor/` precisely so `app/assets/vendor/` is not swept up.
