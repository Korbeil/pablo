---
description: "PABLO : commit, push, PR draft GitHub (description dans la locale configurée du projet) et passage de la tâche en état draft"
permission:
  edit: deny
  write: allow
  read: allow
  grep: allow
  glob: allow
  list: allow
  webfetch: deny
  bash:
    "pablo task:precommit-check*": allow
    "pablo task:state*": allow
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
    "git config*": deny
    "git -c user.name*": deny
    "git -c user.email*": deny
    "*": ask
---

PABLO's commit-and-pr (the original `/commit-and-pr` stays untouched for
non-PABLO work): commit the current changes, push, create the GitHub PR
**as a draft** if none exists (description in the project's configured
locale), then switch the PABLO task to `draft`.

## Context (auto-injected)

PABLO task check:
!`pablo task:precommit-check --json 2>&1`

Current branch:
!`git branch --show-current`

Status:
!`git status --short`

Staged changes:
!`git diff --staged --stat`

Unstaged changes:
!`git diff --stat`

Recent commit style:
!`git log --oneline -10`

Unpushed commits:
!`git log --oneline @{upstream}..HEAD 2>/dev/null || git log --oneline -5`

Branch diff vs base (adjust base branch if wrong):
!`git diff origin/main...HEAD --stat 2>/dev/null || git diff origin/master...HEAD --stat 2>/dev/null || echo "(base branch not found: main/master)"`

PR template:
!`cat .github/pull_request_template.md 2>/dev/null || cat .github/PULL_REQUEST_TEMPLATE.md 2>/dev/null || echo "(no PR template found)"`

Existing PR for this branch:
!`gh pr list --head "$(git branch --show-current)" --state all --json number,title,state,isDraft 2>/dev/null || echo "[]"`

The context only carries diff stats: read actual diffs (`git diff
[--staged] -- <path>`, `git diff <base>...HEAD`) only when you need them
for a commit message or the PR description.

## Hard rules

- **Never touch the Git identity**: no `git config user.*` (any scope), no
  `-c user.name/email`, no `GIT_AUTHOR_*`/`GIT_COMMITTER_*`. If git fails
  with "Please tell me who you are" / "unable to auto-detect email
  address", STOP, report the error verbatim, and tell the user to
  configure their own identity. Never invent one.
- **Guard first** (step 1). If `"allowed": false`, STOP: this command is
  only valid from `in-progress`, `ci-red`, `request-changes` and
  `testing-failed` — report the current state.
- **No changes, no action.** Nothing to commit and no `--force` in
  `$ARGUMENTS` → stop: no push, no PR, no state change. With `--force`,
  skip only the commit step.
- **Push is always** `git push --force-with-lease -u origin <branch>`.
  If rejected, report and stop.
- **Only GitHub write allowed: creating the draft PR.** Never comment on,
  review, or edit an existing PR (no `gh pr comment/edit/review`, no
  `gh api` mutations). If the user asks for a description update, draft
  the body and get explicit confirmation of *that text* first.
- The PR is always `--draft`; never mark it ready (PABLO's poller does).

## Steps

1. **Resolve the worktree and guard.** `pablo task:precommit-check --json`
   succeeds inside the task worktree (JSON with `project`, `branch`,
   `state`, `allowed`, `pr_description_locale`). If it fails with "not a
   PABLO task worktree" (common with OpenChamber), find the worktree: the
   `openchamber` tool's `session.status` (no `sessionId`) gives the
   session's `directory`; otherwise `pablo task:list` when the context
   clearly identifies one task. Then `cd <wt>`, re-run
   `pablo task:precommit-check --worktree <wt> --json`, and run every
   later git/gh/pablo command from `<wt>` (pass `--worktree <wt>` to
   `pablo task:*`). If no worktree can be resolved, say this command only
   works in a PABLO task worktree and point to `/commit-and-pr`.

2. **Commit** (skipped with `--force`).
   - Use already-staged files; otherwise stage the files relevant to the
     work — never blindly `git add -A`; leave out and mention unrelated
     files (local config, `.env`, debug scripts).
   - Task-owned commits already on the branch ("Unpushed commits" shows
     commits of this task, not the base): `git commit --amend --no-edit`.
   - First commit of the task: message following the conventions in the
     log (language, prefixes, ticket refs like `ABC-123`); if none,
     Conventional Commits in English, subject ≤ 72 chars.

3. **PR description** (only when a PR must be created), in the
   `pr_description_locale` from the task check (`en` or `fr`), covering
   the whole branch vs base.
   - With a template: keep every heading, order and checklist; check
     (`[x]`) only items that are genuinely true; remove HTML comments.
   - Without one (headings translated to French when locale is `fr`):

     ```markdown
     ## Description

     <1–3 sentences: what changes for the user and why>

     ## Changes

     - <max 4 bullets — user-visible behavior only>

     ## How to test

     1. <1–3 steps to observe the new behavior>
     ```

   - Concise, functionality-first ("Adds…", "Fixes…"), no file paths or
     class names unless needed, nothing that isn't in the diff; flag
     breaking changes in one line of the Description.

4. **Push and PR.** Push (see Hard rules). No existing PR: write the body
   to `.pablo-pr-body.md` in the worktree root and run
   `gh pr create --draft --title "<title>" --body-file .pablo-pr-body.md`
   (title follows the commit convention, with the issue key if the branch
   has one). Existing PR: just push.

5. **State switch**, only after push (and PR creation) succeeded:
   `pablo task:state draft` (with `--worktree <wt>` if resolved in step
   1). A raw `git push` never changes PABLO state.

6. **Output**: when a PR was created, its title on its own line, then the
   description in a fenced block using **four backticks** (templates
   contain triple-backtick blocks); then one short confirmation — what was committed (or
   skipped via `--force`), the push result, the PR URL, and that the task
   is now 📝 draft.

User request: $ARGUMENTS
