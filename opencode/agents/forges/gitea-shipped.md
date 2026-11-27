**Gitea**: the PR's head branch is `head` in
`gt pr view <n> --no-input --json head,title,state`; what it changes:
`gt pr diff <n> --name-only --no-input`, then a targeted
`gt pr diff <n> --path <file> --no-input`.
