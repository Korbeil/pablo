---
description: "PABLO : commit, push, PR draft GitHub (description en français) et passage de la tâche en état draft"
permission:
  edit: deny
  write: allow
  read: allow
  grep: allow
  glob: allow
  list: allow
  webfetch: deny
  external_directory:
    "/tmp/**": allow
  bash:
    "pablo precommit-check*": allow
    "pablo state*": allow
    "git branch --show-current": allow
    "git status*": allow
    "git diff*": allow
    "git log*": allow
    "git add*": allow
    "git commit*": allow
    "git push*": allow
    "gh pr list*": allow
    "gh pr view*": allow
    "gh pr create*": allow
    "cat .github/pull_request_template.md*": allow
    "cat .github/PULL_REQUEST_TEMPLATE.md*": allow
    "mkdir -p /tmp/pablo-descriptions*": allow
    "cat > /tmp/pablo-descriptions/*": allow
    "tee /tmp/pablo-descriptions/*": allow
    "*": ask
---

PABLO's version of commit-and-pr (the original `/commit-and-pr` still
exists, untouched, for non-PABLO work). Create a git commit for the
current changes, push the branch, create the GitHub PR **as a draft** if
one doesn't exist (with a French description), then switch the PABLO task
state to `draft`.

## Context (auto-injected)

PABLO task check:
!`pablo precommit-check --json 2>&1`

Current branch:
!`git branch --show-current`

Status:
!`git status --short`

Staged diff:
!`git diff --staged`

Unstaged diff:
!`git diff`

Recent commit style:
!`git log --oneline -10`

Unpushed commits:
!`git log --oneline @{upstream}..HEAD 2>/dev/null || git log --oneline -5`

Branch diff vs base (for the PR description — adjust base branch if wrong):
!`git diff origin/main...HEAD --stat 2>/dev/null || git diff origin/master...HEAD --stat 2>/dev/null || echo "(base branch not found: main/master)"`

PR template:
!`cat .github/pull_request_template.md 2>/dev/null || cat .github/PULL_REQUEST_TEMPLATE.md 2>/dev/null || echo "(no PR template found)"`

Existing PR for this branch:
!`gh pr list --head "$(git branch --show-current)" --state all --json number,title,state,isDraft 2>/dev/null || echo "[]"`

## Hard rules

- **Guard first.** If the "PABLO task check" above is an error (exit
  code 2 / not a PABLO task worktree), STOP: tell the user this command
  only works inside a PABLO task worktree and that the original
  `/commit-and-pr` exists for everything else. If `"allowed": false`,
  STOP: `/pablo-commit-and-pr` is only valid from `in-progress`,
  `ci-red`, `request-changes`, and `testing-failed` — report the task's
  current state.
- **No changes, no action.** If there is nothing to commit AND
  `$ARGUMENTS` does not contain `--force`, stop early with a clear
  message: no push, no PR creation, no state change. With `--force`, skip
  only the commit step and continue the rest of the flow (push any
  unpushed commits, create the draft PR if missing, switch the state) —
  for when the user already committed manually.
- The PR description is **always in French**, even if the code and
  commits are in English.
- The PR description is **always printed inside a fenced code block**
  using **four backticks** as the outer fence (templates often contain
  triple-backtick blocks that would break a triple-backtick fence).
- The PR is always created as a **draft** (`--draft`). Never mark it
  ready — PABLO's poller does that when CI is green.

## Steps

1. **Commit** (skipped with `--force`)
   - If files are already staged, commit only those. Otherwise stage the
     files relevant to the work — do NOT blindly `git add -A` if
     unrelated files are lying around (local config, `.env`, debug
     scripts); leave them out and mention them.
   - Write the commit message following the conventions visible in the
     git log above (language, prefixes, ticket refs like `ABC-123`). If
     no convention is detectable, use Conventional Commits in English:
     `type(scope): summary`, subject ≤ 72 chars.

2. **PR description**
   - Cover the **whole branch** vs the base branch, not just this
     commit. Run `git diff <base>...HEAD` for the full diff if the stat
     above isn't enough.
   - If a PR template exists (see above): follow its structure exactly —
     keep every heading, section order, and checklists. Fill each section
     in French based on the actual diff. Check (`[x]`) only checklist
     items that are genuinely true; leave the rest unchecked. Remove HTML
     comments (`<!-- ... -->`) from the final output.
   - If no template exists, use:

     ```markdown
     ## Description

     <résumé clair de ce que fait la PR et pourquoi>

     ## Changements

     - <liste des changements notables>

     ## Comment tester

     1. <étapes de test manuel ou commandes>

     ## Notes

     <points d'attention pour le reviewer — omettre si rien à signaler>
     ```

   - Style: français, direct, sans remplissage ("Cette PR a pour objectif
     de..." → préférer "Ajoute...", "Corrige...", "Refactore...").
   - Base every claim on the actual diff — never invent tests,
     migrations, or impacts that aren't in the code.
   - Explicitly mention breaking changes, new dependencies
     (`composer.json` changes), migrations, and config changes if present.

3. **Push and PR**
   - `git push -u origin <branch>` (plain push — never `--force`; if the
     push is rejected, report it and stop, don't force anything).
   - If the "Existing PR" context above is empty: create the draft PR with
     the French description as body — write the body to
     `/tmp/pablo-descriptions/<branch name>.md` (create the directory
     first with `mkdir -p /tmp/pablo-descriptions` if it doesn't exist —
     always this exact location, it's what this command's `bash:`
     permission allow-list is scoped to) and run
     `gh pr create --draft --title "<title>" --body-file <file>`
     (title follows the same convention as the commit message, mention
     the issue key if the branch has one).
   - If a PR already exists: just push; do not edit the existing PR.

4. **State switch** — final step, only after the push (and PR creation if
   any) succeeded: run `pablo state draft`. This is what moves the PABLO
   task back into the automatic draft → CI → review cycle. (A raw
   `git push` without this command never changes PABLO state — there is
   no push detection.)

5. **Output**
   - The PR title on its own line, then the full French description
     inside the four-backtick fenced block (so the user can copy it or
     tweak the PR afterwards).
   - One short confirmation: what was committed (or that the commit was
     skipped via `--force`), the push result, the PR URL (new or
     existing), and that the task is now 📝 draft.

User request: $ARGUMENTS
