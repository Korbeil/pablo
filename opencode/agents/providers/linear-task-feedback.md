**Linear**: use the `linear` CLI (schpet/linear-cli). QA feedback
   lives in the comments; the issue JSON also has a `history` of state
   transitions.
   - Comments: `linear issue comment list <KEY> --json`
   - Issue: `linear issue view <KEY> --json --show-resolved-threads`
   - Related issues: `linear issue query --search "<QUERY>" --team <TEAM> --json --limit 20`
