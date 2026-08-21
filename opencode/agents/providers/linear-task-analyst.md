**Linear** (`linear.app/<team>/issue/<KEY>` or a `KEY-123` key): use the
   `linear` CLI (schpet/linear-cli) directly — no MCP server, no stored
   tokens.
   - Issue + fields (comments included by default; add
     `--show-resolved-threads` for resolved threads too):
     `linear issue view <KEY> --json`
   - Related issues (full-text search):
     `linear issue query --search "<QUERY>" --team <TEAM> --json --limit 50`
   - Comments alone:
     `linear issue comment list <KEY> --json`
   - Account/workspace context:
     `linear auth whoami` (in multi-workspace setups point any command at a
     specific workspace with `--workspace <slug>`).
