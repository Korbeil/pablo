**Linear** (`linear.app/<team>/issue/<KEY>` or a `KEY-123` key): use the
   `linear` CLI (schpet/linear-cli); add `--workspace <slug>` in
   multi-workspace setups.
   - Issue (comments included): `linear issue view <KEY> --json`
   - Related issues: `linear issue query --search "<QUERY>" --team <TEAM> --json --limit 20`
