---
description: Fusionne les PR ouvertes d'un milestone GitHub dans une branche release/ml-<numéro>, pour la QA
---

# Merge Milestone PRs into a Release Branch

Milestone URL: $ARGUMENTS

Current repo remotes (for context):
!`git remote -v`

Current branch and status:
!`git status -sb`

## Independent of PABLO's task machinery

This command is **not** part of PABLO's task/worktree state machine. It
does not read or write `~/.pablo/state`, does not require running from
inside a task worktree (`/pablo-start`), and never changes any task's
state. It is purely a QA utility: build a throwaway integration branch so
testers can try out everything targeted for a milestone at once. Run it
from a normal clone of the target repo.

## Your task

Merge every open pull request belonging to the GitHub milestone above into
a single new release branch, resolving any merge conflicts automatically,
then produce a final report.

Follow these steps precisely:

### 1. Parse the milestone URL

From the URL in `$ARGUMENTS`, extract:
- `OWNER` and `REPO` (e.g. `https://github.com/OWNER/REPO/milestone/873`)
- `MILESTONE_NUMBER` (the number at the end of the URL)

If the URL does not match the expected format `https://github.com/<owner>/<repo>/milestone/<number>`, stop and report the problem instead of guessing.

### 2. Fetch the milestone and its pull requests

Use the `gh` CLI:

1. Verify the milestone exists and get its title:
   ```
   gh api repos/OWNER/REPO/milestones/MILESTONE_NUMBER
   ```
2. List all pull requests attached to the milestone (the issues endpoint returns both issues and PRs; PRs are the items that have a `pull_request` key):
   ```
   gh api "repos/OWNER/REPO/issues?milestone=MILESTONE_NUMBER&state=all&per_page=100" --paginate \
     --jq '.[] | select(.pull_request != null) | {number: .number, title: .title, state: .state}'
   ```
3. For each PR, get its details to determine its state precisely:
   ```
   gh pr view NUMBER --repo OWNER/REPO --json number,title,state,headRefName,author,mergedAt
   ```
4. Classify the PRs:
   - **To merge:** open PRs
   - **Skipped (already merged):** PRs already merged into the base branch — do NOT merge them again, just list them in the report
   - **Skipped (closed):** PRs closed without being merged — do NOT merge them, list them in the report

If the milestone contains no open PRs, stop and report that there is nothing to merge.

### 3. Create the release branch

1. Determine the repository's default branch:
   ```
   gh repo view OWNER/REPO --json defaultBranchRef --jq .defaultBranchRef.name
   ```
2. Fetch the latest state and create the branch from the up-to-date default branch:
   ```
   git fetch origin
   git checkout -B release/ml-MILESTONE_NUMBER origin/DEFAULT_BRANCH
   ```

The branch name MUST be exactly `release/ml-<milestone number>` (e.g. `release/ml-873`).

### 4. Merge each open PR, one at a time

Sort the open PRs by PR number ascending (oldest first) and merge them sequentially. For each PR:

1. Fetch the PR head into a local ref:
   ```
   git fetch origin pull/NUMBER/head:pr-NUMBER
   ```
2. Merge it with a merge commit so history stays traceable:
   ```
   git merge --no-ff pr-NUMBER -m "Merge PR #NUMBER: <PR title> (milestone ml-MILESTONE_NUMBER)"
   ```
3. **If the merge succeeds cleanly**, record it as merged with no conflicts and move on.
4. **If there are conflicts**, resolve them yourself:
   - Run `git status` and `git diff` to list conflicted files.
   - Open each conflicted file, read BOTH sides of the conflict plus surrounding code, and produce a resolution that preserves the intent of both changes. Never blindly pick `ours` or `theirs` — actually reconcile the logic.
   - If the conflict involves lockfiles or generated files (e.g. `composer.lock`), prefer regenerating them (`composer update --lock`) over hand-editing, when possible.
   - After resolving all files: `git add` the resolved files and `git commit` (keep the merge commit message from step 2, appending a short note of what was resolved).
   - Record for the report: which files conflicted, a one-line summary of each conflict, and how you resolved it.
5. If a conflict is genuinely ambiguous (both sides change the same behavior in incompatible ways and there is no safe way to reconcile them), resolve it with your best judgment, but flag it prominently in the report as **needs human review**.

Do not push the branch and do not delete the temporary `pr-NUMBER` refs until the very end. After the report, clean up with `git branch -D pr-NUMBER` for each temporary ref (keep the release branch).

### 5. Sanity check

After all merges, run a quick validation on the release branch if the project makes it cheap to do so (e.g. `composer validate`, a lint pass, or the project's static analysis). Report any failures — do not try to fix unrelated pre-existing issues.

### 6. Final report

End with a Markdown report in exactly this structure:

```
## Release branch: release/ml-MILESTONE_NUMBER
Milestone: "<milestone title>" (#MILESTONE_NUMBER) — OWNER/REPO
Base: <default branch> @ <short SHA>

### ✅ PRs merged (<count>)
| PR | Title | Author | Conflicts |
|----|-------|--------|-----------|
| #123 | ... | ... | none / N files |

### ⚠️ Conflicts resolved (<count>)
For each conflict:
- **PR #123 — path/to/file.php**: <what conflicted> → <how it was resolved>
- Mark any resolution flagged as "needs human review" with 🔴

### ⏭️ Skipped PRs (<count>)
- #45 — already merged into <default branch>
- #46 — closed without merge

### Next steps
- Branch is local only. Push with: git push -u origin release/ml-MILESTONE_NUMBER
```

Important rules:
- Never force-push, never push automatically — leave the branch local and let the user push.
- Never modify the default branch or any PR branch; all work happens on the release branch.
- If `gh` is not authenticated or a step fails irrecoverably, stop and report exactly what failed and what the user should do.
