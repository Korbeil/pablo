**Jira** (`https://<site>/browse/<KEY>` or a `KEY-123` key): use the
   `acli` CLI (Atlassian CLI) directly — no MCP server, no stored tokens.
   - Issue + fields: `acli jira workitem view <KEY> --json --fields '*all'`
     (default fields are `key,issuetype,summary,status,assignee,description`;
     use `--fields 'summary,status,comment'` to also pull comments, or
     `'*all'` for everything acli exposes).
   - Related issues (JQL):
     `acli jira workitem search --jql "<JQL>" --json --limit 50`
   - Comments: `acli jira workitem comment list --key <KEY> --json`
   - Account/site context: `acli jira auth status`.