**Jira** (`https://<site>/browse/<KEY>` or a `KEY-123` key): use the
   `acli` CLI. Always prefix it with
   `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring` (bypasses
   a locked keyring that would otherwise hang every call); `acli …` below
   means that prefixed form.
   - Issue: `acli jira workitem view <KEY> --json --fields 'summary,status,issuetype,priority,description,labels,issuelinks,comment'`
     (never `'*all'`: it returns every custom field).
   - Comments alone: `acli jira workitem comment list --key <KEY> --json`
   - Related issues: `acli jira workitem search --jql "<JQL>" --json --limit 20`
