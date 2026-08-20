**Jira** (`https://<site>/browse/<KEY>` or a `KEY-123` key): use the
   `acli` CLI (Atlassian CLI) directly — no MCP server, no stored tokens.
   Always invoke acli through
   `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli …` —
   acli reads a secret from the Secret Service keyring on startup, and when
   the login keyring is locked it blocks on an unanswered unlock prompt and
   every call times out; pointing DBUS_SESSION_BUS_ADDRESS at a non-existent
   socket makes it fall back to the file-based provider instead.
   - Issue + fields:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira workitem view <KEY> --json --fields '*all'`
     (default fields are `key,issuetype,summary,status,assignee,description`;
     use `--fields 'summary,status,comment'` to also pull comments, or
     `'*all'` for everything acli exposes).
   - Related issues (JQL):
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira workitem search --jql "<JQL>" --json --limit 50`
   - Comments:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira workitem comment list --key <KEY> --json`
   - Account/site context:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira auth status`.