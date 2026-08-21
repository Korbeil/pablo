mode: primary
model: opencode-go/ox-alpha-free
temperature: 0.2
permission:
  edit: deny
  write: deny
  "github*": deny
  read: allow
  grep: allow
  glob: allow
  list: allow
  webfetch: allow
  bash:
    "*": allow
    "git commit*": deny
    "git push*": deny
    "git checkout*": deny
    "git switch*": deny
    "git merge*": deny
    "git rebase*": deny
    "git reset*": deny
    "git rm*": deny
    "git mv*": deny
    "git stash*": deny
    "git cherry-pick*": deny
    "git revert*": deny
    "git tag*": deny
    "gh pr comment*": deny
    "gh pr edit*": deny
    "gh pr merge*": deny
    "gh pr ready*": deny
    "gh pr close*": deny
    "gh pr review*": deny
    "gh pr create*": deny
    "gh issue edit*": deny
    "gh issue close*": deny
    "gh issue comment*": deny
    "gh issue create*": deny
    "*acli jira workitem edit*": deny
    "*acli jira workitem transition*": deny
    "*acli jira workitem assign*": deny
    "*acli jira workitem create*": deny
    "*acli jira workitem create-bulk*": deny
    "*acli jira workitem comment create*": deny
    "*acli jira workitem comment update*": deny
    "*acli jira workitem comment delete*": deny
    "*acli jira workitem watcher*": deny
    "*acli jira workitem list-watchers*": deny
    "*acli jira workitem link*": deny
    "*acli jira workitem archive*": deny
    "*acli jira workitem delete*": deny
    "*acli jira workitem clone*": deny
    "*acli confluence page create*": deny
    "*acli confluence page update*": deny
    "*acli confluence page delete*": deny
    "*acli confluence space create*": deny
    "*acli confluence space update*": deny
    "*acli confluence space archive*": deny
    "*acli confluence space restore*": deny
    "*acli auth login*": deny
    "*acli auth logout*": deny
    "*acli auth switch*": deny
    "*acli confluence auth login*": deny
    "*acli confluence auth logout*": deny
    "*acli jira auth login*": deny
    "*acli jira auth logout*": deny
    "vendor/bin/*": deny
    "composer*": deny
    "php*": deny
