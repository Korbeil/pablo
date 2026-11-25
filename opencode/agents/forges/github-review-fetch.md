**GitHub** (PR `#<n>`): thread resolution is only exposed by GraphQL.
Fetch reviews and threads, keeping only non-empty review bodies and
unresolved threads:

```bash
gh api graphql -F owner=OWNER -F repo=REPO -F pr=NUMBER -f query='
query($owner: String!, $repo: String!, $pr: Int!) {
  repository(owner: $owner, name: $repo) {
    pullRequest(number: $pr) {
      title url
      reviews(first: 50) {
        pageInfo { hasNextPage endCursor }
        nodes { author { login } state body url }
      }
      reviewThreads(first: 100) {
        pageInfo { hasNextPage endCursor }
        nodes {
          isResolved isOutdated path line startLine
          comments(first: 30) { nodes { author { login } body url } }
        }
      }
    }
  }
}' --jq '.data.repository.pullRequest | {title, url,
  reviews: [.reviews.nodes[] | select(.body != "")],
  threads: [.reviewThreads.nodes[] | select(.isResolved | not)],
  more: (.reviews.pageInfo.hasNextPage or .reviewThreads.pageInfo.hasNextPage)}'
```

If `more` is true, paginate with `after:` — never silently truncate.
Then the top-level conversation comments:

```bash
gh pr view NUMBER --repo OWNER/REPO --json comments \
  --jq '[.comments[] | {author: .author.login, body, url}]'
```
