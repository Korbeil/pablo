**Jira**: use the `acli` CLI (Atlassian CLI) directly — no MCP server,
   no stored tokens. The QA feedback lives in the issue's comments.
   Always invoke acli through
   `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli …` —
   acli reads a secret from the Secret Service keyring on startup, and when
   the login keyring is locked it blocks on an unanswered unlock prompt and
   every call times out; pointing DBUS_SESSION_BUS_ADDRESS at a non-existent
   socket makes it fall back to the file-based provider instead.
   - Comments:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira workitem comment list --key <KEY> --json`
     (output is `{"comments": [...]}`; each comment carries
     `author`, `created`/`updated`, and `body`).
   - Issue context:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira workitem view <KEY> --json --fields '*all'`
   - Related issues (JQL):
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira workitem search --jql "<JQL>" --json --limit 50`
   - Account/site context:
     `env DBUS_SESSION_BUS_ADDRESS=unix:path=/tmp/pablo-nokeyring acli jira auth status`.