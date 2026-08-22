<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

/**
 * Git plumbing: worktrees, branches, and the lease-safe sync sequence.
 *
 * Abstraction so engine services and commands stay testable: tests inject a
 * fake implementing this interface instead of running real git.
 */
interface GitRepoInterface
{
    /**
     * Run a git command in $cwd and return trimmed stdout.
     *
     * @param list<string> $args
     */
    public function git(string $cwd, array $args, bool $check = true): string;

    /** @return list<WorktreeRef> */
    public function listWorktrees(string $repo): array;

    /** @return list<string> */
    public function allBranchNames(string $repo): array;

    public function originUrl(string $repo): ?string;

    public function userEmail(?string $cwd = null): ?string;

    public function createWorktree(string $repo, string $worktreesRoot, string $branch, string $base): string;

    public function removeWorktree(string $repo, string $path, string $branch): void;

    /**
     * Recreate a task worktree from its remote branch (restore path).
     *
     * Unlike createWorktree this checks out an existing pushed branch rather
     * than branching off the primary, so the task continues exactly where it
     * was left on origin.
     */
    public function recreateWorktree(string $repo, string $worktreesRoot, string $branch): string;

    public function refExists(string $cwd, string $ref): bool;

    public function remoteBranchExists(string $repo, string $branch): bool;

    public function cloneRepo(string $originUrl, string $path): void;

    public function addOrigin(string $repo, string $url): void;

    public function fetchOrigin(string $repo): void;

    public function pushWithLease(string $worktree, string $branch): void;

    /**
     * Rebase/merge $upstream into the worktree; report on conflict,
     * aborting the operation on failure.
     *
     * @param callable(string, string): void|null $push
     */
    public function syncWorktree(
        string $wt,
        string $branch,
        string $primary,
        string $strategy,
        bool $apply,
        ?callable $push = null,
        ?string $base = null,
    ): SyncReport;
}
