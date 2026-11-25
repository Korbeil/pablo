**GitLab** (merge request `!<n>`): every thread and comment is a
discussion. Fetch them, keeping the unresolved threads and the plain
comments (system notes are activity, not feedback; `:id` is the current
project, resolved by glab from the origin remote):

```bash
glab api "projects/:id/merge_requests/NUMBER/discussions?per_page=100" | jq '[.[]
  | {resolvable: .notes[0].resolvable, resolved: .notes[0].resolved,
     notes: [.notes[] | select(.system | not)
       | {id, author: .author.username, body, path: .position.new_path, line: .position.new_line}]}
  | select(.notes != [] and ((.resolvable | not) or (.resolved | not)))]'
```

With 100 discussions, read the next page (`&page=2`…) — never silently
truncate. A note's link is the merge request URL + `#note_<id>`. A
reviewer who set "Request changes" without a thread said so in a plain
comment or the review summary: keep those. Treat a thread whose code
moved (`line` null, file gone) as outdated and verify it against the
current code.
