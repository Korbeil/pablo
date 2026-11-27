<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * What YOLO mode's follow-up runs (AgentRuntimeInterface::followUpCommand())
 * may not do, whatever the runtime: they run unattended, with every other
 * tool call approved, so these shell patterns are refused instead.
 *
 * They mirror /pablo-commit-and-pr's hard rules — never touch the git
 * identity, the only forge write is creating the draft PR/MR — plus the git
 * operations that rewrite or move away from the task branch, and every
 * tracker write (trackers stay read-only). Same glob syntax as the agents'
 * `permissions:` (only leading/trailing `*`), so every runtime translates
 * them the way it translates an agent's shell denies.
 */
final class FollowUpPolicy
{
    /** @var list<string> */
    public const DENIED_SHELL = [
        'git config*',
        'git -c user*',
        'git checkout*',
        'git switch*',
        'git merge*',
        'git rebase*',
        'git reset*',
        'git stash*',
        'git cherry-pick*',
        'git revert*',
        'git tag*',
        'git branch -D*',
        'git push origin --delete*',
        'gh pr comment*',
        'gh pr edit*',
        'gh pr merge*',
        'gh pr ready*',
        'gh pr close*',
        'gh pr review*',
        'gh issue*',
        'gh api*',
        'gh repo*',
        'gh release*',
        'glab mr note*',
        'glab mr update*',
        'glab mr merge*',
        'glab mr approve*',
        'glab mr revoke*',
        'glab mr close*',
        'glab mr reopen*',
        'glab mr delete*',
        'glab issue*',
        'glab api*',
        'glab repo*',
        'glab release*',
        'glab ci*',
        'gt pr close*',
        'gt pr reopen*',
        'gt pr edit*',
        'gt pr ready*',
        'gt pr merge*',
        'gt pr review*',
        'gt pr comment*',
        'gt pr update-branch*',
        'gt pr backport*',
        'gt pr clean*',
        'gt pr checkout*',
        'gt pr lock*',
        'gt pr unlock*',
        'gt pr pin*',
        'gt pr unpin*',
        'gt pr delete*',
        'gt pr rm*',
        'gt pr attachment*',
        'gt issue*',
        'gt api*',
        'gt repo*',
        'gt release*',
        'gt run*',
        'gt workflow*',
        'gt auth*',
        'gt config*',
        'gt wiki*',
        'gt label*',
        'gt milestone*',
        'gt secret*',
        'gt variable*',
        '*acli jira workitem*',
        '*acli confluence*',
        '*acli auth*',
        '*acli jira auth*',
        '*acli confluence auth*',
        '*linear issue*',
    ];
}
