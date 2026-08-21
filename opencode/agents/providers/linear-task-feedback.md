**Linear**: use the `linear` CLI (schpet/linear-cli) directly — no MCP
   server, no stored tokens. The QA feedback lives in the issue's comments;
   its JSON also carries a `history` array of past state transitions.
   - Comments:
     `linear issue comment list <KEY> --json`
   - Issue context:
     `linear issue view <KEY> --json --show-resolved-threads`
   - Related issues (full-text search):
     `linear issue query --search "<QUERY>" --team <TEAM> --json --limit 50`
   - Account/workspace context:
     `linear auth whoami`.
