**GitHub** (PR `#<n>`):

1. `gh pr checks <n> --repo <owner>/<repo>` — every failing, errored,
   cancelled or action-required check is your worklist. Use
   `gh pr view <n> --json statusCheckRollup` only if a conclusion is
   ambiguous.
2. Logs, bounded — never dump a whole log:
   - **GitHub Actions** (has a `run-id`):
     `gh run view <run-id> --log-failed [--job <job-id>] | tail -n 200`,
     or grep it for `error|fail|exception` first and widen only around
     the hits.
   - **CircleCI** (context starts with `ci/circleci:`): parse `<ORG>`,
     `<REPO>`, `<JOB-NUMBER>` from the check's details URL
     (`…/pipelines/github/<ORG>/<REPO>/<n>/workflows/<uuid>/jobs/<JOB-NUMBER>`)
     and fetch only that job:
     `curl -s -H "Circle-Token: $CIRCLECI_TOKEN" "https://circleci.com/api/v2/project/github/<ORG>/<REPO>/<JOB-NUMBER>/output" | tail -c 20000`.
     Retry once after 5s on 404/429/5xx and report the status. If
     `$CIRCLECI_TOKEN` is unset or returns 401, report the check metadata
     (name, state, URL), say logs are unavailable, and mention that
     exporting `CIRCLECI_TOKEN` in `~/.bashrc` enables them. Never loop
     over every job or paginate through pipelines.
