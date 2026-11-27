**Gitea** (PR `#<n>`; pass `--no-input` to every `gt` call):

1. `gt pr checks <n> --no-input --json name,state,description,url --jq '.[] | select(.state != "success" and .state != "skipped" and .state != "pending")'`
   — every check left is your worklist (`warning` is worth a note, never
   a blocker). A check is one commit status of the head commit: one per
   Gitea Actions job, or one from external CI.
2. Logs, bounded — never dump a whole log:
   - **Gitea Actions** (the check's `url` is a job URL,
     `…/actions/runs/<run>/jobs/<job>`): `gt run logs <job-url> --no-input 2>/dev/null | tail -n 200`,
     or grep it for `error|fail|exception` first and widen only around
     the hits; `gt run logs <run> --failed --tail 200 --no-input` for every
     failed job of a run.
   - **External CI** (another `url`): report the check's name, state and
     URL, and fetch that job's log from its CI only if it offers a CLI you
     already have.
