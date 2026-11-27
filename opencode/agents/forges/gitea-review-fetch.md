**Gitea** (PR `#<n>`; pass `--no-input` to every `gt` call): a thread is a
review comment, unresolved while it has no `resolver`; `gt api` fills
`{owner}`/`{repo}` from the checkout. Fetch the reviews, keeping the
non-empty bodies (pending and dismissed reviews left out), then the
unresolved comments of every review that has some:

```bash
gt api 'repos/{owner}/{repo}/pulls/NUMBER/reviews?limit=50' --no-input --jq '[.[]
  | select(.state != "PENDING" and (.dismissed | not))
  | {id, author: .user.login, state, body, comments_count, html_url}]'
gt api 'repos/{owner}/{repo}/pulls/NUMBER/reviews/REVIEW_ID/comments' --no-input --jq '[.[]
  | select(.resolver == null)
  | {author: .user.login, body, path, line: .position, html_url}]'
```

Then the top-level conversation comments:

```bash
gt api 'repos/{owner}/{repo}/issues/NUMBER/comments?limit=50' --no-input \
  --jq '[.[] | {author: .user.login, body, html_url}]'
```

With 50 items, read the next page (`&page=2`…) — never silently truncate.
A review state is `APPROVED`, `REQUEST_CHANGES` or `COMMENT`. Treat a
comment whose `line` is 0 or null (code moved) as outdated and verify it
against the current code.
