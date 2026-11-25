**GitLab** (merge request `!<n>`):

1. The head pipeline's failed jobs — every one not `allowFailure` is your
   worklist (an allowed failure is worth a note, never a blocker):
   `glab api graphql -f path=<group/project> -f iid=<n> -f query='query($path: ID!, $iid: String!) { project(fullPath: $path) { mergeRequest(iid: $iid) { headPipeline { status webPath jobs(retried: false, statuses: [FAILED], first: 50) { nodes { id name allowFailure webPath stage { name } } } } } } }'`
2. Logs, bounded — never dump a whole log: the job's numeric id ends its
   `id` (`gid://gitlab/Ci::Build/<job-id>`);
   `glab api "projects/:id/jobs/<job-id>/trace" | tail -n 200`, or grep it
   for `error|fail|exception` first and widen only around the hits. A
   trigger (bridge) job fails with its downstream pipeline: read that
   pipeline's failed jobs the same way.
