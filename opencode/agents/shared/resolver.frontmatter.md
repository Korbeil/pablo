mode: primary
model: deepinfra/zai-org/GLM-5.3-Flash
temperature: 0.5
permission:
  edit: allow
  write: allow
  "github*": deny
  read: allow
  grep: allow
  glob: allow
  list: allow
  bash:
    "*": allow
    "git commit*": allow
    "git push*": allow
    "git checkout*": allow
    "git switch*": allow
    "git merge*": allow
    "git rebase*": allow
    "git reset*": allow
    "git rm*": allow
    "git mv*": allow
    "git stash*": allow
    "git cherry-pick*": allow
    "git revert*": allow
    "git tag*": allow
    "git add*": allow
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
