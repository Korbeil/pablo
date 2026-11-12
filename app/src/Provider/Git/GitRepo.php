<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

use Pablo\Support\PabloError;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Exception\RuntimeException as ProcessRuntimeException;
use Symfony\Component\Process\Process;

/**
 * Git plumbing: worktrees, branches, and the lease-safe sync sequence.
 *
 * Sync rules: always fetch and integrate the branch's own remote first, then
 * rebase/merge onto the primary branch; push rewritten history with
 * --force-with-lease only; on a lease failure roll back and let the next
 * cycle retry; never resolve conflicts — abort and report them.
 *
 * Every git call gets an explicit timeout chosen by the kind of operation
 * (Symfony Process would otherwise kill it after 60 s), and a timeout surfaces
 * as a PabloError so the lease rollback and the rebase/merge abort still run.
 */
final class GitRepo implements GitRepoInterface
{
    /** Read-only plumbing (rev-parse, status, for-each-ref, diff, config…). */
    public const TIMEOUT_LOCAL = 'local';
    /** Operations rewriting the working tree (worktree add, checkout, reset, rebase, merge), hooks included. */
    public const TIMEOUT_WORKTREE = 'worktree';
    /** Network operations (fetch, push — a pre-push hook may run a test suite). */
    public const TIMEOUT_NETWORK = 'network';
    /** A full clone (backup restore). */
    public const TIMEOUT_CLONE = 'clone';

    /** Default seconds per kind; generous on purpose, they only stop a hung git. */
    public const DEFAULT_TIMEOUTS = [
        self::TIMEOUT_LOCAL => 120.0,
        self::TIMEOUT_WORKTREE => 900.0,
        self::TIMEOUT_NETWORK => 1200.0,
        self::TIMEOUT_CLONE => 3600.0,
    ];

    private const NETWORK_COMMANDS = ['fetch', 'push', 'pull', 'ls-remote'];
    private const WORKTREE_COMMANDS = ['worktree', 'checkout', 'switch', 'reset', 'rebase', 'merge', 'cherry-pick', 'revert'];

    /** @var array<string, float> */
    private readonly array $timeouts;

    /**
     * @param array<string, float> $timeouts per-kind overrides of DEFAULT_TIMEOUTS (tests shrink them)
     */
    public function __construct(array $timeouts = [])
    {
        $this->timeouts = array_merge(self::DEFAULT_TIMEOUTS, $timeouts);
    }

    /**
     * @param list<string> $args
     */
    public function git(string $cwd, array $args, bool $check = true): string
    {
        $process = $this->run(array_merge(['git', '-C', $cwd], $args), $this->kindOf($args));
        if ($check && !$process->isSuccessful()) {
            $err = '' !== trim($process->getErrorOutput()) ? trim($process->getErrorOutput()) : trim($process->getOutput());
            throw new PabloError('git '.implode(' ', $args)." failed in {$cwd}: {$err}");
        }

        return trim($process->getOutput());
    }

    /**
     * The single place git processes are run: applies the kind's timeout and
     * turns a timeout (or a git that cannot be executed) into a PabloError,
     * whatever $check says — a killed git has no meaningful exit code.
     *
     * @param list<string> $argv
     */
    private function run(array $argv, string $kind): Process
    {
        $timeout = $this->timeouts[$kind];
        $process = new Process($argv);
        $process->setTimeout($timeout);
        $command = implode(' ', \array_slice($argv, 'clone' === ($argv[1] ?? '') ? 1 : 3));
        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            throw new PabloError(\sprintf('git %s timed out after %ss', $command, $timeout));
        } catch (ProcessRuntimeException $e) {
            throw new PabloError(\sprintf('git %s could not run: %s', $command, $e->getMessage()));
        }

        return $process;
    }

    /** @param list<string> $args */
    private function kindOf(array $args): string
    {
        $command = $args[0] ?? '';
        if (\in_array($command, self::NETWORK_COMMANDS, true)) {
            return self::TIMEOUT_NETWORK;
        }
        if ('clone' === $command) {
            return self::TIMEOUT_CLONE;
        }

        return \in_array($command, self::WORKTREE_COMMANDS, true) ? self::TIMEOUT_WORKTREE : self::TIMEOUT_LOCAL;
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

    public function createWorktree(string $repo, string $worktreesRoot, string $branch, string $base, string $remote = 'origin'): string
    {
        if (!is_dir($worktreesRoot)) {
            @mkdir($worktreesRoot, 0o777, true);
        }
        $path = rtrim($worktreesRoot, '/').'/'.$branch;
        if (file_exists($path)) {
            throw new PabloError("worktree path already exists: {$path}");
        }
        $this->git($repo, ['fetch', $remote], check: false); // best effort; base may be local-only
        $start = $base;
        if ($this->refExists($repo, "{$remote}/{$base}")) {
            $start = "{$remote}/{$base}";
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
        $process = $this->run(['git', '-C', $cwd, 'rev-parse', '--verify', '--quiet', $ref], self::TIMEOUT_LOCAL);

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
        $process = $this->run(['git', 'clone', $originUrl, $path], self::TIMEOUT_CLONE);
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

    public function updatePrimary(string $repo, string $branch, string $remote = 'origin'): MainUpdateReport
    {
        if (!is_dir($repo) || 'true' !== $this->git($repo, ['rev-parse', '--is-inside-work-tree'], check: false)) {
            return new MainUpdateReport($repo, $branch, 'missing', detail: 'not a git checkout');
        }
        $gitDir = $this->git($repo, ['rev-parse', '--absolute-git-dir']);
        foreach (['rebase-merge', 'rebase-apply', 'MERGE_HEAD', 'CHERRY_PICK_HEAD', 'REVERT_HEAD', 'BISECT_LOG'] as $marker) {
            if (file_exists("{$gitDir}/{$marker}")) {
                return new MainUpdateReport($repo, $branch, 'busy', detail: "operation in progress ({$marker})");
            }
        }
        $current = $this->git($repo, ['branch', '--show-current']);
        $from = '' !== $current ? $current : 'detached HEAD';
        if ('' !== $this->git($repo, ['status', '--porcelain'])) {
            return new MainUpdateReport($repo, $branch, 'dirty', $from, detail: 'uncommitted changes; skipped');
        }

        $this->git($repo, ['fetch', '--prune', $remote]);
        $remoteRef = "{$remote}/{$branch}";
        if (!$this->refExists($repo, $remoteRef)) {
            throw new PabloError("{$remoteRef} not found in {$repo}");
        }

        $hasLocal = $this->refExists($repo, "refs/heads/{$branch}");
        $oldSha = $hasLocal ? $this->git($repo, ['rev-parse', "refs/heads/{$branch}"]) : '';
        if ($hasLocal) {
            $ahead = (int) $this->git($repo, ['rev-list', '--count', "{$remoteRef}..refs/heads/{$branch}"]);
            if ($ahead > 0) {
                return new MainUpdateReport($repo, $branch, 'ahead', $from, $oldSha, detail: "local {$branch} is {$ahead} commit(s) ahead of {$remoteRef}; skipped");
            }
        }

        if ($current !== $branch) {
            $here = realpath($repo);
            foreach ($this->listWorktrees($repo) as $ref) {
                if ($ref->branch === $branch && realpath($ref->path) !== $here) {
                    return new MainUpdateReport($repo, $branch, 'checked-out-elsewhere', $from, $oldSha, detail: "{$branch} is checked out in {$ref->path}");
                }
            }
            $this->git($repo, $hasLocal ? ['checkout', '-q', $branch] : ['checkout', '-q', '-b', $branch, $remoteRef]);
        }

        $this->git($repo, ['reset', '-q', '--hard', $remoteRef]);
        $newSha = $this->git($repo, ['rev-parse', 'HEAD']);

        return new MainUpdateReport($repo, $branch, $oldSha === $newSha ? 'up-to-date' : 'updated', $from, $oldSha, $newSha);
    }

    /**
     * Rebase/merge $upstream into the worktree; report on conflict,
     * aborting the operation on failure. A timeout (or a git that cannot run)
     * aborts too, then rethrows the PabloError: it is not a conflict.
     */
    private function integrate(string $wt, string $upstream, string $strategy): ?SyncReport
    {
        $op = 'rebase' === $strategy
            ? ['rebase', $upstream]
            : ['merge', '--no-edit', $upstream];
        $abort = 'rebase' === $strategy ? 'rebase' : 'merge';
        try {
            $process = $this->run(['git', '-C', $wt, ...$op], self::TIMEOUT_WORKTREE);
        } catch (PabloError $e) {
            try {
                $this->git($wt, [$abort, '--abort'], check: false);
            } catch (PabloError) {
                // the abort hanging too must not hide the original failure
            }

            throw $e;
        }
        if ($process->isSuccessful()) {
            return null;
        }
        $conflictFiles = $this->git($wt, ['diff', '--name-only', '--diff-filter=U'], check: false);
        $hunks = $this->git($wt, ['diff', '--diff-filter=U'], check: false);
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
        string $remote = 'origin',
    ): SyncReport {
        return $this->doSync($wt, $branch, $primary, $strategy, $apply, $push ?? $this->pushWithLease(...), $base, $remote);
    }

    private function doSync(
        string $wt,
        string $branch,
        string $primary,
        string $strategy,
        bool $apply,
        callable $push,
        ?string $base,
        string $remote,
    ): SyncReport {
        $this->git($wt, ['fetch', '--prune', $remote]);

        $remoteBranch = "{$remote}/{$branch}";
        $hasRemote = $this->refExists($wt, $remoteBranch);
        $remoteNew = $hasRemote ? $this->counts($wt, $remoteBranch)->behind : 0;

        $target = $this->refExists($wt, "{$remote}/{$primary}") ? "{$remote}/{$primary}" : $primary;
        if (null !== $base && '' !== $base && $this->refExists($wt, "{$remote}/{$base}")) {
            // A stacked branch tracks its PR's actual base (e.g. another open
            // branch) rather than the primary, so integrate against that to
            // keep the stacking intact.
            $target = "{$remote}/{$base}";
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

        try {
            // 1. Integrate the branch's own remote first, so a collaborator's push
            //    is never clobbered and the lease check won't trip on their commits.
            if ($remoteNew) {
                $conflict = $this->integrate($wt, $remoteBranch, $strategy);
                if (null !== $conflict) {
                    $conflict->branch = $branch;
                    $conflict->target = $target;

                    return $conflict;
                }
            }

            // 2. Rebase/merge onto the target (primary branch, or the PR's base).
            $conflict = $this->integrate($wt, $target, $strategy);
        } catch (PabloError $e) {
            // integrate() already aborted; also undo a completed step 1.
            $this->git($wt, ['reset', '--hard', $origHead], check: false);

            throw $e;
        }
        if (null !== $conflict) {
            $this->git($wt, ['reset', '--hard', $origHead], check: false);
            $conflict->branch = $branch;
            $conflict->target = $target;

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
                    detail: "push failed (remote moved or timed out); rolled back, will retry next cycle ({$e->getMessage()})",
                );
            }
        }

        return new SyncReport($wt, $branch, 'synced', behind: $behind, ahead: $ahead);
    }
}
