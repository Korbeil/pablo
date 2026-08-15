**Jira**: use the `acli` CLI (Atlassian CLI) directly — no MCP server,
   no stored tokens. The QA feedback lives in the issue's comments:
   - Comments: `acli jira workitem comment list --key <KEY> --json`
     (output is `{"comments": [...]}`; each comment carries
     `author`, `created`/`updated`, and `body`).
   - Issue context: `acli jira workitem view <KEY> --json --fields '*all'`
   - Related issues (JQL):
     `acli jira workitem search --jql "<JQL>" --json --limit 50`
   - Account/site context: `acli jira auth status`.