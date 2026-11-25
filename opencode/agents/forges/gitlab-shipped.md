**GitLab** (merge request `!<n>`): its source branch is `source_branch` in
`glab mr view -F json`; what it changes: `glab mr view` /
`glab mr diff --raw | grep '^diff --git'`, then a targeted
`glab mr diff --raw`.
