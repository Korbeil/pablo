**Jira**: use the `acli` CLI. Always prefix it with
   `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring` (bypasses
   a locked keyring that would otherwise hang every call); `acli …` below
   means that prefixed form. QA feedback lives in the comments.
   - Comments: `acli jira workitem comment list --key <KEY> --json`
     (`{"comments": [...]}` with `author`, `created`/`updated`, `body`).
   - Issue: `acli jira workitem view <KEY> --json --fields 'summary,status,issuetype,priority,description,labels,issuelinks'`
     (never `'*all'`: it returns every custom field).
   - Related issues: `acli jira workitem search --jql "<JQL>" --json --limit 20`
