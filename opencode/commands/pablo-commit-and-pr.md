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
    "*": ask
---

PABLO's version of commit-and-pr (the original `/commit-and-pr` still
exists, untouched, for non-PABLO work). Create a git commit for the
current changes, push the branch, create the GitHub PR **as a draft** if
one doesn't exist (with a description in the project's configured PR
locale), then switch the PABLO task state to `draft`.

## Context (auto-injected)

PABLO task check:
!`pablo task:precommit-check --json 2>&1`

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

- **Resolve the worktree, then guard.** The PABLO task check resolves the
  task from the current working directory. It succeeds only when run inside
  the task worktree (e.g. `~/.pablo/worktrees/<project>/<branch>`). If the
  agent isn't running inside the worktree (common with OpenChamber agents),
  the check fails with "not a PABLO task worktree" — do NOT stop: first
  resolve the task worktree (see step 1), then re-run the guard with
  `pablo task:precommit-check --worktree <path> --json`. If `"allowed":
  false`, STOP: `/pablo-commit-and-pr` is only valid from `in-progress`,
  `ci-red`, `request-changes`, and `testing-failed` — report the task's
  current state. If the worktree genuinely cannot be resolved, tell the
  user this command only works inside a PABLO task worktree and that the
  original `/commit-and-pr` exists for everything else.
- **No changes, no action.** If there is nothing to commit AND
  `$ARGUMENTS` does not contain `--force`, stop early with a clear
  message: no push, no PR creation, no state change. With `--force`, skip
  only the commit step and continue the rest of the flow (push any
  unpushed commits, create the draft PR if missing, switch the state) —
  for when the user already committed manually.
- **Amend by default.** If the branch already has task-owned commits (use
  the "Unpushed commits" context above — if it shows commits that belong
  to this branch, not the base): use `git commit --amend --no-edit`
  (never change the existing commit message). If this is the very first
  `/pablo-commit-and-pr` for this task (no prior commits on the branch):
  create a new commit with a message following conventions as described
  below.
- **Push is always `--force-with-lease`.** Use
  `git push --force-with-lease -u origin <branch>` for every push — this
  is safe (refuses if the remote has diverged) and works for first pushes
  too.
- The PR description is **always written in the project's configured PR
  description locale** — read `pr_description_locale` from the "PABLO task
  check" JSON above (either `en` or `fr`). Write the whole body in that
  locale, even if the code and commits are in another language.
- The PR description is **always printed inside a fenced code block**
  using **four backticks** as the outer fence (templates often contain
  triple-backtick blocks that would break a triple-backtick fence).
- The PR is always created as a **draft** (`--draft`). Never mark it
  ready — PABLO's poller does that when CI is green.

## Steps

1. **Resolve the task worktree** (if not already inside one)
   - First run `pablo task:precommit-check --json`. If it succeeds (returns
     JSON with the task's `project`, `branch`, `state`, `allowed`,
     `pr_description_locale`), you are already inside the task worktree —
     use that cwd for everything and skip to step 2.
   - If it errors with "not a PABLO task worktree" because the cwd isn't the
     worktree, resolve the worktree path from the current OpenChamber
     session:
     - Use the `openchamber` tool (`session.status` with no `sessionId`
       returns the current session's `directory`, or `session.list`) to read
       the directory of the session you're running in.
     - If that directory is a PABLO task worktree, use it as the worktree.
     - Otherwise list active tasks (`pablo task:list`) and, if the session
       or user context clearly identifies one task, use its worktree.
   - Once you have a worktree path `<wt>`: `cd <wt>` and re-run the guard
     with `pablo task:precommit-check --worktree <wt> --json`. Run **all**
     subsequent `git`/`gh`/`pablo task:state` commands from inside `<wt>`
     (pass `--worktree <wt>` to the `pablo task:*` calls), so the branch,
     status, diff and push all operate on the right worktree.
   - If `"allowed": false`, stop and report the task's current state (see
     Hard rules). If the worktree genuinely cannot be resolved, stop and
     point the user to `/commit-and-pr`.

2. **Commit** (skipped with `--force`)
   - Determine if this branch already has task-owned commits — check the
     "Unpushed commits" context above. If it shows commits that belong to
     this task (not the base branch), the branch has existing commits.
   - If files are already staged, use those. Otherwise stage the files
     relevant to the work — do NOT blindly `git add -A` if unrelated
     files are lying around (local config, `.env`, debug scripts); leave
     them out and mention them.
   - **Existing commits on this branch:** `git commit --amend --no-edit`
     (do not change the existing commit message).
   - **First commit for this task (no prior commits on this branch):**
     write the commit message following the conventions visible in the
     git log above (language, prefixes, ticket refs like `ABC-123`). If
     no convention is detectable, use Conventional Commits in English:
     `type(scope): summary`, subject ≤ 72 chars. Then `git commit -m
     "<message>"`.

3. **PR description**
   - Cover the **whole branch** vs the base branch, not just this
     commit. Run `git diff <base>...HEAD` for the full diff if the stat
     above isn't enough.
   - If a PR template exists (see above): follow its structure exactly —
      keep every heading, section order, and checklists. Fill each section
      in the configured PR locale (`pr_description_locale` from the "PABLO
      task check" JSON) based on the actual diff. Check (`[x]`) only
      checklist items that are genuinely true; leave the rest unchecked.
      Remove HTML comments (`<!-- ... -->`) from the final output.
   - If no template exists, use the following structure (headings in the
     configured locale — English shown here, translate to French if
     `pr_description_locale` is `fr`):

     ```markdown
     ## Description

     <clear summary of what this PR does and why>

     ## Changes

     - <list of notable changes>

     ## How to test

     1. <manual test steps or commands>

     ## Notes

     <attention points for the reviewer — omit if nothing to flag>
     ```

   - Style: direct, no filler ("This PR aims to..." → prefer "Adds...",
     "Fixes...", "Refactors..."), written in the configured locale.
   - Base every claim on the actual diff — never invent tests,
     migrations, or impacts that aren't in the code.
   - Explicitly mention breaking changes, new dependencies
     (`composer.json` changes), migrations, and config changes if present.

4. **Push and PR**
   - `git push --force-with-lease -u origin <branch>` (always use
     `--force-with-lease` — safe for first pushes and rejects only if the
     remote truly diverged; if the push is rejected, report it and stop).
   - If the "Existing PR" context above is empty: create the draft PR with
      the description (in the configured locale) as body — write the body
      to `.pablo-pr-body.md` (a dotfile in the worktree root) and run
     `gh pr create --draft --title "<title>" --body-file <file>`
     (title follows the same convention as the commit message, mention
     the issue key if the branch has one).
   - If a PR already exists: just push; do not edit the existing PR.

5. **State switch** — final step, only after the push (and PR creation if
   any) succeeded: run `pablo task:state draft` (pass `--worktree <wt>` if
   you resolved the worktree in step 1). This is what moves the PABLO
   task back into the automatic draft → CI → review cycle. (A raw
   `git push` without this command never changes PABLO state — there is
   no push detection.)

6. **Output**
   - The PR title on its own line, then the full description (in the
      configured locale) inside the four-backtick fenced block (so the
      user can copy it or tweak the PR afterwards).
   - One short confirmation: what was committed (or that the commit was
     skipped via `--force`), the push result, the PR URL (new or
     existing), and that the task is now 📝 draft.

User request: $ARGUMENTS
