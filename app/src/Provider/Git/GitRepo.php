<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

use Pablo\Support\PabloError;
use Symfony\Component\Process\Process;

/**
 * Git plumbing: worktrees, branches, and the lease-safe sync sequence.
 *
 * Sync rules: always fetch and integrate the branch's own remote first, then
 * rebase/merge onto the primary branch; push rewritten history with
 * --force-with-lease only; on a lease failure roll back and let the next
 * cycle retry; never resolve conflicts — abort and report them.
 */
final class GitRepo implements GitRepoInterface
{
    /**
     * @param list<string> $args
     */
    public function git(string $cwd, array $args, bool $check = true): string
    {
        $process = new Process(array_merge(['git', '-C', $cwd], $args));
        $process->run();
        if ($check && !$process->isSuccessful()) {
            $err = '' !== trim($process->getErrorOutput()) ? trim($process->getErrorOutput()) : trim($process->getOutput());
            throw new PabloError('git '.implode(' ', $args)." failed in {$cwd}: {$err}");
        }

        return trim($process->getOutput());
    }

    /** @return list<WorktreeRef> */
    public function listWorktrees(string $repo): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\r?\n/', $this->git($repo, ['worktree', 'list', '--porcelain'])) ?: [] as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $current = trim(substr($line, \strlen('worktree ')));
            } elseif (str_starts_with($line, 'branch ') && null !== $current) {
                $branch = str_starts_with($line, 'branch refs/heads/') ? substr($line, \strlen('branch refs/heads/')) : substr($line, \strlen('branch '));
                $entries[] = new WorktreeRef($current, $branch);
                $current = null;
            }
        }

        return $entries;
    }

    /** @return list<string> */
    public function allBranchNames(string $repo): array
    {
        $names = [];
        $out = $this->git($repo, ['for-each-ref', '--format=%(refname:short)', 'refs/heads', 'refs/remotes']);
        foreach (preg_split('/\r?\n/', $out) ?: [] as $ref) {
            $ref = trim($ref);
            if ('' === $ref || str_ends_with($ref, '/HEAD')) {
                continue;
            }
            if (str_starts_with($ref, 'origin/')) {
                $ref = substr($ref, \strlen('origin/'));
            }
            $names[$ref] = true;
        }

        return array_keys($names);
    }

    public function originUrl(string $repo): ?string
    {
        try {
            return $this->git($repo, ['remote', 'get-url', 'origin']);
        } catch (PabloError) {
            return null;
        }
    }

    public function userEmail(?string $cwd = null): ?string
    {
        if (null !== $cwd && '' !== $cwd && (is_dir($cwd.'/.git') || is_dir($cwd))) {
            try {
                $local = $this->git($cwd, ['config', 'user.email']);
                if ('' !== $local) {
                    return $local;
                }
            } catch (PabloError) {
            }
        }
        try {
            $global = $this->git('', ['config', '--global', 'user.email']);
            if ('' !== $global) {
                return $global;
            }
        } catch (PabloError) {
        }

        return null;
    }

    public function createWorktree(string $repo, string $worktreesRoot, string $branch, string $base): string
    {
        if (!is_dir($worktreesRoot)) {
            @mkdir($worktreesRoot, 0o777, true);
        }
        $path = rtrim($worktreesRoot, '/').'/'.$branch;
        if (file_exists($path)) {
            throw new PabloError("worktree path already exists: {$path}");
        }
        $this->git($repo, ['fetch', 'origin'], check: false); // best effort; base may be local-only
        $start = $base;
        if ($this->refExists($repo, "origin/{$base}")) {
            $start = "origin/{$base}";
        }
        $this->git($repo, ['worktree', 'add', '-b', $branch, $path, $start]);

        return $path;
    }

    public function removeWorktree(string $repo, string $path, string $branch): void
    {
        if (is_dir($path)) {
            $this->git($repo, ['worktree', 'remove', $path]);
        } else {
            $this->git($repo, ['worktree', 'prune']);
        }
        $this->deleteBranches($repo, $branch);
    }

    /**
     * Delete every local branch matching $branch. A task tracks its branch by
     * its short name (e.g. "pim-559"), but the actual git branch may carry a
     * slash prefix (e.g. "octocat/pim-559"); match either the exact name or
     * any branch ending in "/$branch". Missing branches are ignored so a
     * close never fails just because the branch is already gone.
     */
    private function deleteBranches(string $repo, string $branch): void
    {
        $names = [];
        $out = $this->git($repo, ['for-each-ref', '--format=%(refname:short)', 'refs/heads']);
        foreach (preg_split('/\r?\n/', $out) ?: [] as $ref) {
            $ref = trim($ref);
            if ('' === $ref || ($ref !== $branch && !str_ends_with($ref, '/'.$branch))) {
                continue;
            }
            $names[] = $ref;
        }
        foreach ($names as $name) {
            $this->git($repo, ['branch', '-D', $name], check: false);
        }
    }

    public function refExists(string $cwd, string $ref): bool
    {
        $process = new Process(['git', '-C', $cwd, 'rev-parse', '--verify', '--quiet', $ref]);
        $process->run();

        return 0 === $process->getExitCode();
    }

    /**
     * Recreate a task worktree from its remote branch (restore path).
     *
     * Unlike createWorktree this checks out an existing pushed branch rather
     * than branching off the primary, so the task continues exactly where it
     * was left on origin.
     */
    public function recreateWorktree(string $repo, string $worktreesRoot, string $branch): string
    {
        $this->git($repo, ['fetch', 'origin'], check: false);
        if (!is_dir($worktreesRoot)) {
            @mkdir($worktreesRoot, 0o777, true);
        }
        $path = rtrim($worktreesRoot, '/').'/'.$branch;
        if (file_exists($path)) {
            throw new PabloError("worktree path already exists: {$path}");
        }
        if ($this->refExists($repo, $branch)) {
            $this->git($repo, ['worktree', 'add', $path, $branch]);
        } else {
            $this->git($repo, ['worktree', 'add', '-b', $branch, $path, "origin/{$branch}"]);
        }

        return $path;
    }

    public function remoteBranchExists(string $repo, string $branch): bool
    {
        return $this->refExists($repo, "origin/{$branch}");
    }

    public function cloneRepo(string $originUrl, string $path): void
    {
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $process = new Process(['git', 'clone', $originUrl, $path]);
        $process->run();
        if (!$process->isSuccessful()) {
            $err = '' !== trim($process->getErrorOutput()) ? trim($process->getErrorOutput()) : trim($process->getOutput());
            throw new PabloError("git clone {$originUrl} failed: {$err}");
        }
    }

    public function addOrigin(string $repo, string $url): void
    {
        $this->git($repo, ['remote', 'add', 'origin', $url]);
    }

    public function fetchOrigin(string $repo): void
    {
        $this->git($repo, ['fetch', 'origin']);
    }

    public function pushWithLease(string $worktree, string $branch): void
    {
        $this->git($worktree, ['push', '--force-with-lease', 'origin', $branch]);
    }

    /**
     * Rebase/merge $upstream into the worktree; report on conflict,
     * aborting the operation on failure.
     */
    private function integrate(string $wt, string $upstream, string $strategy): ?SyncReport
    {
        $op = 'rebase' === $strategy
            ? ['rebase', $upstream]
            : ['merge', '--no-edit', $upstream];
        $process = new Process(['git', '-C', $wt, ...$op]);
        $process->run();
        if ($process->isSuccessful()) {
            return null;
        }
        $conflictFiles = $this->git($wt, ['diff', '--name-only', '--diff-filter=U'], check: false);
        $hunks = $this->git($wt, ['diff', '--diff-filter=U'], check: false);
        $abort = 'rebase' === $strategy ? 'rebase' : 'merge';
        $this->git($wt, [$abort, '--abort'], check: false);
        $detail = implode("\n", \array_slice(preg_split('/\r?\n/', $hunks) ?: [], 0, 40));
        if ('' === $detail) {
            $detail = '' !== trim($process->getErrorOutput()) ? trim($process->getErrorOutput()) : trim($process->getOutput());
        }

        return new SyncReport(
            worktree: $wt,
            branch: '',
            action: 'conflict',
            conflictFiles: array_values(array_filter(preg_split('/\r?\n/', $conflictFiles) ?: [], static fn ($l) => '' !== $l)),
            detail: $detail,
        );
    }

    /** Commit counts of HEAD relative to $upstream. */
    private function counts(string $wt, string $upstream): AheadBehind
    {
        $out = $this->git($wt, ['rev-list', '--left-right', '--count', "{$upstream}...HEAD"]);
        $parts = preg_split('/\s+/', trim($out));
        [$left, $right] = false === $parts ? ['0', '0'] : $parts;

        return new AheadBehind((int) $left, (int) $right);
    }

    public function syncWorktree(
        string $wt,
        string $branch,
        string $primary,
        string $strategy,
        bool $apply,
        ?callable $push = null,
        ?string $base = null,
    ): SyncReport {
        return $this->doSync($wt, $branch, $primary, $strategy, $apply, $push ?? $this->pushWithLease(...), $base);
    }

    private function doSync(
        string $wt,
        string $branch,
        string $primary,
        string $strategy,
        bool $apply,
        callable $push,
        ?string $base,
    ): SyncReport {
        $this->git($wt, ['fetch', '--prune', 'origin']);

        $remoteBranch = "origin/{$branch}";
        $hasRemote = $this->refExists($wt, $remoteBranch);
        $remoteNew = $hasRemote ? $this->counts($wt, $remoteBranch)->behind : 0;

        $target = $this->refExists($wt, "origin/{$primary}") ? "origin/{$primary}" : $primary;
        if (null !== $base && '' !== $base && $this->refExists($wt, "origin/{$base}")) {
            // A stacked branch tracks its PR's actual base (e.g. another open
            // branch) rather than the primary, so integrate against that to
            // keep the stacking intact.
            $target = "origin/{$base}";
        }
        $counts = $this->counts($wt, $target);
        $behind = $counts->behind;
        $ahead = $counts->ahead;

        if (0 === $behind && 0 === $remoteNew) {
            return new SyncReport($wt, $branch, 'up-to-date', behind: $behind, ahead: $ahead);
        }

        if (!$apply) {
            $detail = "{$strategy} onto {$target}";
            if ($remoteNew) {
                $detail = "integrate {$remoteNew} commit(s) from {$remoteBranch}, then {$detail}";
            }

            return new SyncReport($wt, $branch, 'would-sync', behind: $behind, ahead: $ahead, detail: $detail);
        }

        if ('' !== $this->git($wt, ['status', '--porcelain'])) {
            return new SyncReport(
                $wt,
                $branch,
                'dirty',
                behind: $behind,
                ahead: $ahead,
                detail: 'uncommitted changes in worktree; sync skipped',
            );
        }

        $origHead = $this->git($wt, ['rev-parse', 'HEAD']);

        // 1. Integrate the branch's own remote first, so a collaborator's push
        //    is never clobbered and the lease check won't trip on their commits.
        if ($remoteNew) {
            $conflict = $this->integrate($wt, $remoteBranch, $strategy);
            if (null !== $conflict) {
                $conflict->branch = $branch;

                return $conflict;
            }
        }

        // 2. Rebase/merge onto the primary branch.
        $conflict = $this->integrate($wt, $target, $strategy);
        if (null !== $conflict) {
            $this->git($wt, ['reset', '--hard', $origHead], check: false);
            $conflict->branch = $branch;

            return $conflict;
        }

        // 3. Push (lease-protected) if the branch exists remotely and moved.
        if ($hasRemote && $this->git($wt, ['rev-parse', 'HEAD']) !== $this->git($wt, ['rev-parse', $remoteBranch])) {
            try {
                $push($wt, $branch);
            } catch (PabloError $e) {
                $this->git($wt, ['reset', '--hard', $origHead]);

                return new SyncReport(
                    $wt,
                    $branch,
                    'lease-failed',
                    behind: $behind,
                    ahead: $ahead,
                    detail: "remote moved during sync; rolled back, will retry next cycle ({$e->getMessage()})",
                );
            }
        }

        return new SyncReport($wt, $branch, 'synced', behind: $behind, ahead: $ahead);
    }
}
