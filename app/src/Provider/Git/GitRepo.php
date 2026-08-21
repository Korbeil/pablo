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
final class GitRepo
{
    /**
     * @param list<string> $args
     */
    public static function git(string $cwd, array $args, bool $check = true): string
    {
        $process = new Process(array_merge(['git', '-C', $cwd], $args));
        $process->run();
        if ($check && !$process->isSuccessful()) {
            $err = '' !== trim($process->getErrorOutput()) ? trim($process->getErrorOutput()) : trim($process->getOutput());
            throw new PabloError('git '.implode(' ', $args)." failed in {$cwd}: {$err}");
        }

        return trim($process->getOutput());
    }

    /** @return array<int, array{0: string, 1: string}> (path, branch) per worktree */
    public static function listWorktrees(string $repo): array
    {
        $entries = [];
        $current = null;
        foreach (preg_split('/\r?\n/', self::git($repo, ['worktree', 'list', '--porcelain'])) ?: [] as $line) {
            if (str_starts_with($line, 'worktree ')) {
                $current = trim(substr($line, \strlen('worktree ')));
            } elseif (str_starts_with($line, 'branch ') && null !== $current) {
                $branch = str_starts_with($line, 'branch refs/heads/') ? substr($line, \strlen('branch refs/heads/')) : substr($line, \strlen('branch '));
                $entries[] = [$current, $branch];
                $current = null;
            }
        }

        return $entries;
    }

    /** @return array<int, string> */
    /** @var callable|null test seam: (string): list<string> */
    private static $allBranchNames;

    public static function setAllBranchNames(?callable $fn): void
    {
        self::$allBranchNames = $fn;
    }

    /** @return list<string> */
    public static function allBranchNames(string $repo): array
    {
        if (null !== self::$allBranchNames) {
            return (self::$allBranchNames)($repo);
        }

        return self::realAllBranchNames($repo);
    }

    /** @return list<string> */
    private static function realAllBranchNames(string $repo): array
    {
        $names = [];
        $out = self::git($repo, ['for-each-ref', '--format=%(refname:short)', 'refs/heads', 'refs/remotes']);
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

    /** @var callable|null test seam: (string): ?string */
    private static $originUrl;

    public static function setOriginUrl(?callable $fn): void
    {
        self::$originUrl = $fn;
    }

    public static function originUrl(string $repo): ?string
    {
        if (null !== self::$originUrl) {
            return (self::$originUrl)($repo);
        }
        try {
            return self::git($repo, ['remote', 'get-url', 'origin']);
        } catch (PabloError) {
            return null;
        }
    }

    /** @var callable|null test seam: (?string): ?string */
    private static $userEmail;

    public static function setUserEmail(?callable $fn): void
    {
        self::$userEmail = $fn;
    }

    public static function userEmail(?string $cwd = null): ?string
    {
        if (null !== self::$userEmail) {
            return (self::$userEmail)($cwd);
        }
        if (null !== $cwd && '' !== $cwd && (is_dir($cwd.'/.git') || is_dir($cwd))) {
            try {
                $local = self::git($cwd, ['config', 'user.email']);
                if ('' !== $local) {
                    return $local;
                }
            } catch (PabloError) {
            }
        }
        try {
            $global = self::git('', ['config', '--global', 'user.email']);
            if ('' !== $global) {
                return $global;
            }
        } catch (PabloError) {
        }

        return null;
    }

    /** @var callable|null test seam: (string, string, string, string): string */
    private static $createWorktree;

    public static function setCreateWorktree(?callable $fn): void
    {
        self::$createWorktree = $fn;
    }

    public static function createWorktree(string $repo, string $worktreesRoot, string $branch, string $base): string
    {
        if (null !== self::$createWorktree) {
            return (self::$createWorktree)($repo, $worktreesRoot, $branch, $base);
        }
        if (!is_dir($worktreesRoot)) {
            @mkdir($worktreesRoot, 0o777, true);
        }
        $path = rtrim($worktreesRoot, '/').'/'.$branch;
        if (file_exists($path)) {
            throw new PabloError("worktree path already exists: {$path}");
        }
        self::git($repo, ['fetch', 'origin'], check: false); // best effort; base may be local-only
        $start = $base;
        if (self::refExists($repo, "origin/{$base}")) {
            $start = "origin/{$base}";
        }
        self::git($repo, ['worktree', 'add', '-b', $branch, $path, $start]);

        return $path;
    }

    /** @var callable|null test seam: (string, string, string): void */
    private static $removeWorktree;

    public static function setRemoveWorktree(?callable $fn): void
    {
        self::$removeWorktree = $fn;
    }

    public static function removeWorktree(string $repo, string $path, string $branch): void
    {
        if (null !== self::$removeWorktree) {
            (self::$removeWorktree)($repo, $path, $branch);

            return;
        }
        self::git($repo, ['worktree', 'remove', $path]);
        self::deleteBranches($repo, $branch);
    }

    /**
     * Delete every local branch matching $branch. A task tracks its branch by
     * its short name (e.g. "pim-559"), but the actual git branch may carry a
     * slash prefix (e.g. "octocat/pim-559"); match either the exact name or
     * any branch ending in "/$branch". Missing branches are ignored so a
     * close never fails just because the branch is already gone.
     */
    private static function deleteBranches(string $repo, string $branch): void
    {
        $names = [];
        $out = self::git($repo, ['for-each-ref', '--format=%(refname:short)', 'refs/heads']);
        foreach (preg_split('/\r?\n/', $out) ?: [] as $ref) {
            $ref = trim($ref);
            if ('' === $ref || ($ref !== $branch && !str_ends_with($ref, '/'.$branch))) {
                continue;
            }
            $names[] = $ref;
        }
        foreach ($names as $name) {
            self::git($repo, ['branch', '-D', $name], check: false);
        }
    }

    public static function refExists(string $cwd, string $ref): bool
    {
        $process = new Process(['git', '-C', $cwd, 'rev-parse', '--verify', '--quiet', $ref]);
        $process->run();

        return 0 === $process->getExitCode();
    }

    /** @var callable|null test seam: (string, string, string): string */
    private static $recreateWorktree;

    public static function setRecreateWorktree(?callable $fn): void
    {
        self::$recreateWorktree = $fn;
    }

    /**
     * Recreate a task worktree from its remote branch (restore path).
     *
     * Unlike createWorktree this checks out an existing pushed branch rather
     * than branching off the primary, so the task continues exactly where it
     * was left on origin.
     */
    public static function recreateWorktree(string $repo, string $worktreesRoot, string $branch): string
    {
        if (null !== self::$recreateWorktree) {
            return (self::$recreateWorktree)($repo, $worktreesRoot, $branch);
        }
        self::git($repo, ['fetch', 'origin'], check: false);
        if (!is_dir($worktreesRoot)) {
            @mkdir($worktreesRoot, 0o777, true);
        }
        $path = rtrim($worktreesRoot, '/').'/'.$branch;
        if (file_exists($path)) {
            throw new PabloError("worktree path already exists: {$path}");
        }
        if (self::refExists($repo, $branch)) {
            self::git($repo, ['worktree', 'add', $path, $branch]);
        } else {
            self::git($repo, ['worktree', 'add', '-b', $branch, $path, "origin/{$branch}"]);
        }

        return $path;
    }

    public static function remoteBranchExists(string $repo, string $branch): bool
    {
        return self::refExists($repo, "origin/{$branch}");
    }

    /** @var callable|null test seam: (string, string): void */
    private static $cloneRepo;

    public static function setCloneRepo(?callable $fn): void
    {
        self::$cloneRepo = $fn;
    }

    public static function cloneRepo(string $originUrl, string $path): void
    {
        if (null !== self::$cloneRepo) {
            (self::$cloneRepo)($originUrl, $path);

            return;
        }
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

    public static function addOrigin(string $repo, string $url): void
    {
        self::git($repo, ['remote', 'add', 'origin', $url]);
    }

    public static function fetchOrigin(string $repo): void
    {
        self::git($repo, ['fetch', 'origin']);
    }

    public static function pushWithLease(string $worktree, string $branch): void
    {
        self::git($worktree, ['push', '--force-with-lease', 'origin', $branch]);
    }

    /**
     * Rebase/merge $upstream into the worktree; report on conflict,
     * aborting the operation on failure.
     */
    private static function integrate(string $wt, string $upstream, string $strategy): ?SyncReport
    {
        $op = 'rebase' === $strategy
            ? ['rebase', $upstream]
            : ['merge', '--no-edit', $upstream];
        $process = new Process(['git', '-C', $wt, ...$op]);
        $process->run();
        if ($process->isSuccessful()) {
            return null;
        }
        $conflictFiles = self::git($wt, ['diff', '--name-only', '--diff-filter=U'], check: false);
        $hunks = self::git($wt, ['diff', '--diff-filter=U'], check: false);
        $abort = 'rebase' === $strategy ? 'rebase' : 'merge';
        self::git($wt, [$abort, '--abort'], check: false);
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

    /** @return array{0: int, 1: int} (behind, ahead) of $upstream vs HEAD */
    private static function counts(string $wt, string $upstream): array
    {
        $out = self::git($wt, ['rev-list', '--left-right', '--count', "{$upstream}...HEAD"]);
        $parts = preg_split('/\s+/', trim($out));
        [$left, $right] = false === $parts ? ['0', '0'] : $parts;

        return [(int) $left, (int) $right];
    }

    public static function syncWorktree(
        string $wt,
        string $branch,
        string $primary,
        string $strategy,
        bool $apply,
        ?callable $push = null,
        ?string $base = null,
    ): SyncReport {
        $push ??= [self::class, 'pushWithLease'];
        self::git($wt, ['fetch', '--prune', 'origin']);

        $remoteBranch = "origin/{$branch}";
        $hasRemote = self::refExists($wt, $remoteBranch);
        $remoteNew = $hasRemote ? self::counts($wt, $remoteBranch)[0] : 0;

        $target = self::refExists($wt, "origin/{$primary}") ? "origin/{$primary}" : $primary;
        if (null !== $base && '' !== $base && self::refExists($wt, "origin/{$base}")) {
            // A stacked branch tracks its PR's actual base (e.g. another open
            // branch) rather than the primary, so integrate against that to
            // keep the stacking intact.
            $target = "origin/{$base}";
        }
        [$behind, $ahead] = self::counts($wt, $target);

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

        if ('' !== self::git($wt, ['status', '--porcelain'])) {
            return new SyncReport(
                $wt,
                $branch,
                'dirty',
                behind: $behind,
                ahead: $ahead,
                detail: 'uncommitted changes in worktree; sync skipped',
            );
        }

        $origHead = self::git($wt, ['rev-parse', 'HEAD']);

        // 1. Integrate the branch's own remote first, so a collaborator's push
        //    is never clobbered and the lease check won't trip on their commits.
        if ($remoteNew) {
            $conflict = self::integrate($wt, $remoteBranch, $strategy);
            if (null !== $conflict) {
                $conflict->branch = $branch;

                return $conflict;
            }
        }

        // 2. Rebase/merge onto the primary branch.
        $conflict = self::integrate($wt, $target, $strategy);
        if (null !== $conflict) {
            self::git($wt, ['reset', '--hard', $origHead], check: false);
            $conflict->branch = $branch;

            return $conflict;
        }

        // 3. Push (lease-protected) if the branch exists remotely and moved.
        if ($hasRemote && self::git($wt, ['rev-parse', 'HEAD']) !== self::git($wt, ['rev-parse', $remoteBranch])) {
            try {
                $push($wt, $branch);
            } catch (PabloError $e) {
                self::git($wt, ['reset', '--hard', $origHead]);

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
