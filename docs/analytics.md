# Analytics

A local, append-only event log of task lifetimes and agent runs, plus the
`pablo stats` command that aggregates it. Everything stays under
`~/.pablo/analytics/` — nothing leaves the machine, and no prompt content is
ever persisted.

## What is collected

Five event types, one JSON line each:

| Event | Emitted by | Payload highlights |
|---|---|---|
| `task_opened` | `task:start` (once, at record creation) | initial state, issue provider/key or null, prompt length, worktree |
| `state_entered` | `StateMachine::enterState()` after the transition persisted | from → to |
| `agent_run_started` | `internal:launch-agent`, before the backend launches | run id, agent, backend (`orca`/`openchamber`/headless), worktree, launch time |
| `agent_run_finished` | `internal:watch-agent`, once the run concluded | started/finished/duration, tokens (`input`, `output`, `reasoning`, `cache_read`, `cache_write`, `tokens_total`), cost, models, session id, harvest quality |
| `task_closed` | `task:close` **and** the poller's merge auto-close, just before the record is deleted | opened/closed time, final state, merged flag, PR number, per-agent attempt counts |

**Exactly-once runs.** The watcher normally emits `agent_run_finished` and
marks its launch `reported` in the same persisted save. The poller's
reconciliation sweep then catches anything left over — healed launches,
watchers that died before reporting, pre-analytics legacy — emitting the
missing event (usage harvested with `window` quality; `backend: unknown`)
and marking it reported. Every concluded PABLO-triggered run therefore
appears exactly once, regardless of which component observed the
conclusion, at no extra cost beyond the existing poll cadence.

That guarantee is enforced at three layers, so a bug or version skew in any
one component can never double-count a run:

1. **Caller idempotence** — both emitters skip runs already marked
   `reported`: a slow watcher never re-reports after the sweep, and the
   sweep never re-emits after the watcher. Launches also persist their run
   id at launch time, so sweep-emitted events keep the same id as their
   `agent_run_started`.
2. **Log-level guard** — `agentRunFinished` refuses to append an event whose
   identity (project + branch + agent + started_at + finished_at) already
   exists in the current or previous month file.
3. **Read-time dedup** — the aggregator collapses finished events sharing
   that identity into one run, keeping the richest row (real backend over
   `unknown`, better harvest quality, non-null usage). Historical duplicates
   from earlier builds are thus invisible to `pablo stats` and the dashboard
   even though they remain in the log.

From these, `pablo stats` derives:

- **Task lifetimes** — open→close duration avg/p50/p90 and merge rate per
  project.
- **Agent runs** — count per agent type, average wall-clock runtime, token
  totals with **cache hit rate** (`cache_read / context` where
  `context = input + cache_read + cache_write`), accumulated cost, and the
  average human wait between an agent finishing and the next state change.
- **State dwell times** — average time spent in each state, with entry
  counts doubling as the rework-loop counter (ci-red / request-changes
  churn).

## Storage layout

```text
~/.pablo/analytics/
├── <project>/
│   └── YYYY-MM.jsonl     # one event per line, ts-ordered on read
└── analytics-errors.log  # best-effort failures only; normally empty
```

Files rotate by month. Recording is strictly best-effort: any I/O failure
is swallowed into `analytics-errors.log` and can never break a task
operation. The root honours `PABLO_ANALYTICS_DIR` for tests/overrides,
resolved per process like every other `~/.pablo` path (never frozen into
the DI container cache).

## Token/cost harvesting

Where usage is read from depends on how the run was launched:

- **OpenChamber backend** — those sessions live behind OpenChamber's own
  OpenCode server, which the plain `opencode` CLI's project-scoped
  `session list` / `export` cannot see. PABLO therefore aggregates the
  session rows returned by `openchamber session list --dir <worktree>`
  (per-session tokens, cache, cost and model), keeping every session created
  at/after the launch time (minus a small grace) → `harvest: "window"`.
  Rows carry no transcripts, so prompt fingerprints are not matched on this
  path.
- **Orca / headless `opencode run`** — both land in the same local OpenCode
  storage. PABLO lists recent sessions whose directory matches the worktree
  and whose creation is not before the launch time:
  - With a prompt fingerprint available (sha256 prefix of the launch prompt,
    computed in memory), each candidate session is exported and matched
    against its first user message → `harvest: "exact"`.
  - Without a match (or without a fingerprint) every candidate in the window
    is merged → `harvest: "window"`.
- Nothing found (CLI absent, no candidates, empty output) → usage fields are
  null and `harvest: "none"`; timing is still recorded.

Token accounting follows OpenCode's own numbers — `input` / `output` /
`reasoning` / `cache.read` / `cache.write` / total plus cost and model id —
summed per assistant message on the OpenCode-storage path and pre-summed per
session on the OpenChamber path. Note `cache.write` counts tokens written to
the prompt cache during that step, so hit rate is a per-run aggregate —
which is what makes agent-to-agent comparison meaningful. Subscription
plans report zero cost on both paths, so accumulated cost can legitimately
stay at zero while token counts are real.

## Privacy

No prompt content is stored. Only lengths and sha256 fingerprints (16 hex
chars, non-reversible) are recorded, used solely to attribute the right
OpenCode session to the right run.

## Usage

```bash
pablo stats                        # last 30 days, all projects, tables
pablo stats --days 7 --project wallet-kit
pablo stats --type open-source     # same type tabs as the /analytics page
pablo stats --agent ci-analyst     # filter only the agents table
pablo stats --format json          # machine-readable aggregation
```

The same aggregation powers the dashboard's **/analytics** page (linked
from the task board header): KPI tiles plus UX Chart.js graphs of the
daily throughput, tokens per agent, cache hit %, runtime, cost and state
dwell — see [docs/dashboard.md](dashboard.md#the-analytics-page-analytics).
The reader (`Pablo\Analytics\AnalyticsReader`) and aggregator
(`Pablo\Analytics\AnalyticsAggregator`) are reusable services, so any
future surface reads the same numbers rather than duplicating them.
