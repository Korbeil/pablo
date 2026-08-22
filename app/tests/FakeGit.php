<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Git\SyncReport;
use Pablo\Support\PabloError;

/**
 * Test double for GitRepoInterface. Mirrors the old GitRepo::set* seams as
 * configurable callables/records instead of global mutable state.
 */
final class FakeGit implements GitRepoInterface
{
    /** @var callable|string|null */
    public $originUrl;

    /** @var callable|list<string>|null */
    public $allBranchNames;

    /** @var callable|string|null */
    public $userEmail;

    /** @var callable|null fn(repo, root, branch, base): string */
    public $createWorktree;

    /** @var list<array{0: string, 1: string}> recorded (repo, path) removals */
    public array $removed = [];

    /** @var callable|null fn(url, path): void */
    public $cloneRepo;

    /** @var callable|null fn(repo, root, branch): string */
    public $recreateWorktree;

    /** @var list<array{0: string, 1: string}> recorded (url, path) clones */
    public array $clones = [];

    /** @var SyncReport|null canned sync result */
    public ?SyncReport $syncReport = null;

    /** @var list<array<string, mixed>> every syncWorktree() invocation */
    public array $syncCalls = [];

    /** @var list<array{0: string, 1: list<string>}> raw args of every git() call */
    public array $gitCalls = [];

    /** @var string canned stdout for git() */
    public string $gitOutput = '';

    /** When set, git() throws PabloError with this message. */
    public ?string $gitThrows = null;

    public function resolveOriginUrl(string $repo): ?string
    {
        if (\is_callable($this->originUrl)) {
            return ($this->originUrl)($repo);
        }

        return \is_string($this->originUrl) ? $this->originUrl : null;
    }

    public function git(string $cwd, array $args, bool $check = true): string
    {
        $this->gitCalls[] = [$cwd, $args];
        if (null !== $this->gitThrows) {
            throw new PabloError($this->gitThrows);
        }

        return $this->gitOutput;
    }

    public function listWorktrees(string $repo): array
    {
        return [];
    }

    public function allBranchNames(string $repo): array
    {
        if (\is_callable($this->allBranchNames)) {
            return ($this->allBranchNames)($repo);
        }

        return \is_array($this->allBranchNames) ? $this->allBranchNames : [];
    }

    public function originUrl(string $repo): ?string
    {
        return $this->resolveOriginUrl($repo);
    }

    public function userEmail(?string $cwd = null): ?string
    {
        if (\is_callable($this->userEmail)) {
            return ($this->userEmail)($cwd);
        }

        return \is_string($this->userEmail) ? $this->userEmail : null;
    }

    public function createWorktree(string $repo, string $worktreesRoot, string $branch, string $base): string
    {
        if (null !== $this->createWorktree) {
            return ($this->createWorktree)($repo, $worktreesRoot, $branch, $base);
        }

        return rtrim($worktreesRoot, '/').'/'.$branch;
    }

    /** When set, removeWorktree() throws PabloError with this message. */
    public ?string $removeThrows = null;

    public function removeWorktree(string $repo, string $path, string $branch): void
    {
        if (null !== $this->removeThrows) {
            throw new PabloError($this->removeThrows);
        }
        $this->removed[] = [$path, $branch];
    }

    public function recreateWorktree(string $repo, string $worktreesRoot, string $branch): string
    {
        if (null !== $this->recreateWorktree) {
            return ($this->recreateWorktree)($repo, $worktreesRoot, $branch);
        }

        return rtrim($worktreesRoot, '/').'/'.$branch;
    }

    public function refExists(string $cwd, string $ref): bool
    {
        return false;
    }

    public function remoteBranchExists(string $repo, string $branch): bool
    {
        return false;
    }

    public function cloneRepo(string $originUrl, string $path): void
    {
        $this->clones[] = [$originUrl, $path];
        if (null !== $this->cloneRepo) {
            ($this->cloneRepo)($originUrl, $path);
        }
    }

    public function addOrigin(string $repo, string $url): void
    {
    }

    public function fetchOrigin(string $repo): void
    {
    }

    public function pushWithLease(string $worktree, string $branch): void
    {
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
        $this->syncCalls[] = ['wt' => $wt, 'branch' => $branch, 'apply' => $apply, 'base' => $base];

        return $this->syncReport ?? new SyncReport(worktree: $wt, branch: $branch, action: 'up-to-date');
    }

    public function trackerSlugFor(ProjectConfig $cfg): string
    {
        return '';
    }
}
