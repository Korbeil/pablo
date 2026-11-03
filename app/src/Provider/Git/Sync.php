<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeResolver;
use Pablo\Agents\Runtime\SessionRef;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Store\TaskLockedException;
use Pablo\Support\PabloError;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;

/**
 * Per-project worktree sync.
 *
 * Keeps every task worktree up to date with the project's primary branch.
 * Dry-run by default; changes are only applied with sync.auto_apply or an
 * explicit apply=true.
 */
final class Sync
{
    public const SYNC_LOCK_TIMEOUT_S = 2;

    public const ACTION_ICONS = [
        'up-to-date' => '💚',
        'would-sync' => '🔄',
        'synced' => '💚',
        'conflict' => '🚫',
        'lease-failed' => '🔁',
        'dirty' => '📝',
        'locked' => '🔐',
    ];

    public function __construct(
        private readonly GitRepoInterface $git,
        private readonly GhPrInterface $gh,
        private readonly RepoSlug $repoSlug,
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time,
        private readonly AgentRuntimeResolver $runtimes = new AgentRuntimeResolver(),
    ) {
    }

    /**
     * Task worktrees: git worktree list minus the primary checkout.
     *
     * @return list<WorktreeRef>
     */
    private function discover(ProjectConfig $cfg): array
    {
        $out = [];
        foreach ($this->git->listWorktrees($cfg->repoPath) as $ref) {
            if ((string) realpath($ref->path) !== (string) realpath($cfg->repoPath)
                && $ref->branch !== $cfg->primaryBranch) {
                $out[] = $ref;
            }
        }

        return $out;
    }

    /**
     * The branch a task's PR is stacked on, if it differs from the primary.
     * PRs live on GitHub whatever the issue tracker (a Jira- or Linear-tracked
     * repo still has GitHub PRs), so this keys off the repo being on GitHub,
     * not `issue_tracker.provider`. A repo that is not on GitHub, and any
     * branch without an open PR targeting a non-primary base, syncs against
     * the primary. A gh failure propagates: falling back to the primary would
     * silently rebase a stacked branch onto main and break the stack.
     *
     * @param list<string> $branches
     *
     * @return array<string, string> branch => resolved base
     */
    private function resolveStackedBases(ProjectConfig $cfg, array $branches): array
    {
        $baseByBranch = [];
        if ([] === $branches) {
            return $baseByBranch;
        }
        try {
            $slug = $this->repoSlug->for($cfg);
        } catch (PabloError) {
            return $baseByBranch; // not a GitHub repo: no PRs to stack on
        }
        $prs = $this->gh->prsForBranches($slug, $branches);
        foreach ($branches as $branch) {
            $pr = $prs[$branch] ?? null;
            if (null === $pr || 'OPEN' !== $pr->state) {
                // A merged/closed PR's base is stale; only an open PR's counts.
                continue;
            }
            $base = $pr->baseRefName;
            if (null !== $base && '' !== $base && $base !== $cfg->primaryBranch) {
                $baseByBranch[$branch] = $base;
            }
        }

        return $baseByBranch;
    }

    /** @return array<int, SyncReport> */
    public function syncProject(ProjectConfig $cfg, Store $store, ?bool $apply, ?AgentLauncherInterface $agents = null): array
    {
        $effectiveApply = $apply ?? $cfg->syncAutoApply;
        $reports = [];
        $branches = [];
        $byBranch = [];
        foreach ($this->discover($cfg) as $ref) {
            $path = $ref->path;
            $branch = $ref->branch;
            if ('' === $branch || null === $store->get($cfg->name, $branch)) {
                // Not (or no longer) a PABLO task worktree: leave it alone. Only
                // active tasks are kept in sync, so an orphaned or foreign
                // worktree is never rebased and never shows up in the log.
                continue;
            }
            $branches[] = $branch;
            $byBranch[$branch] = $path;
        }
        $stacked = $this->resolveStackedBases($cfg, $branches);
        foreach ($branches as $branch) {
            $path = $byBranch[$branch];
            try {
                $lock = $store->taskLock($cfg->name, $branch, self::SYNC_LOCK_TIMEOUT_S);
                try {
                    $reports[] = $this->git->syncWorktree(
                        $path,
                        $branch,
                        $cfg->primaryBranch,
                        $cfg->syncStrategy,
                        $effectiveApply,
                        base: $stacked[$branch] ?? null,
                        remote: $cfg->primaryRemote,
                    );
                } finally {
                    $lock->release();
                }
            } catch (TaskLockedException) {
                $reports[] = new SyncReport(
                    worktree: $path,
                    branch: $branch,
                    action: 'locked',
                    detail: 'task busy (state poller or a command holds it); will retry next cycle',
                );
            }
        }
        foreach ($reports as $report) {
            if ('conflict' === $report->action && $effectiveApply && null !== $agents) {
                $prompt = $this->buildConflictAgentPrompt($report, $cfg);
                // Claude Code and Copilot take a PABLO-assigned session id;
                // OpenCode picks its own, found back from its session list.
                $runtime = $this->runtimes->resolve();
                $sessionId = $runtime->newSessionId();
                $agents->launchHeadless($report->worktree, 'rebase-conflict-resolver', $prompt, $cfg->defaultModel, $sessionId);
                if (null === $sessionId) {
                    sleep(1);
                    $sessionId = $this->findRecentSession($report->worktree, 'rebase-conflict-resolver');
                }
                $report->agentHandle = null !== $sessionId ? SessionRef::handle($runtime->name(), $sessionId) : 'launched';
            }
        }
        $this->saveLastLog($cfg->name, $cfg->syncStrategy, $reports);

        return $reports;
    }

    public static function logsDir(): string
    {
        $override = getenv('PABLO_LOGS_DIR');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return (getenv('HOME') ?: '~').'/.pablo/logs';
    }

    /** @param list<SyncReport> $reports */
    public function saveLastLog(string $projectName, string $strategy, array $reports): void
    {
        $path = self::logsDir()."/rebase-last-{$projectName}.json";
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $log = new RebaseLog($projectName, $this->time->utcnow(), $strategy, $reports);
        file_put_contents($path, json_encode($log->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * The resolver must rebase onto the exact ref the engine used
     * ($report->target): the PR's base when stacked, else the primary — and
     * the primary too when a stacked base no longer exists on the remote.
     */
    public function buildConflictAgentPrompt(SyncReport $report, ProjectConfig $cfg): string
    {
        $remote = $cfg->primaryRemote;
        $primaryTarget = $remote.'/'.$cfg->primaryBranch;
        $target = '' !== $report->target ? $report->target : $primaryTarget;
        $stacked = $target !== $primaryTarget && $target !== $cfg->primaryBranch;
        $lines = [
            "PABLO sync hit rebase conflicts on branch `{$report->branch}`.",
            "Rebase onto `{$target}` using strategy `{$cfg->syncStrategy}` was aborted.",
            'The worktree is clean — you must re-run the rebase yourself.',
            '',
            '1. `git fetch --prune '.implode(' ', array_unique([$remote, 'origin'])).'`',
            "2. If `{$remote}/{$report->branch}` has new commits, rebase onto it first.",
            "3. `git rebase {$target}`",
            '4. Resolve every conflict. `git add` resolved files, `git rebase --continue`.',
            '5. Repeat until the rebase completes cleanly.',
            "6. `git push --force-with-lease origin {$report->branch}`",
            '',
            'Conflicting files from the original attempt:',
        ];
        if ($stacked) {
            array_splice($lines, 3, 0, [
                '',
                "This branch's PR is stacked on `{$target}` (not `{$primaryTarget}`),",
                "so keep the stacking intact by rebasing onto `{$target}` — do NOT rebase",
                'onto the primary branch unless the stack base really has changed.',
            ]);
        }
        foreach ($report->conflictFiles as $f) {
            $lines[] = "  - {$f}";
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Returns the most recent opencode session id for the $worktree, or null.
     * OpenCode v2 removed the `db` subcommand (this used to query the sqlite
     * store directly, filtered by agent); `session list` only exposes a
     * directory — the worktree's newest session is a right-enough hint for
     * the "fix agent running" display, since the resolver runs right after
     * the conflict and nothing else is concurrent there.
     */
    private function findRecentSession(string $worktree, string $agent): ?string
    {
        $probe = $this->runner->probe([
            'opencode', 'session', 'list', '--format', 'json', '--max-count', '50',
        ], timeout: 5, cwd: $worktree);
        if (0 !== $probe->exitCode) {
            return null;
        }
        $rows = json_decode($probe->output, true);
        if (!\is_array($rows)) {
            return null;
        }
        $wanted = rtrim($worktree, '/');
        usort($rows, static fn (array $a, array $b) => (int) ($b['created'] ?? 0) <=> (int) ($a['created'] ?? 0));
        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }
            if (rtrim((string) ($row['directory'] ?? ''), '/') !== $wanted) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            if ('' !== $id) {
                return $id;
            }
        }

        return null;
    }

    public function loadLastLog(string $projectName): ?RebaseLog
    {
        $path = self::logsDir()."/rebase-last-{$projectName}.json";
        if (!is_file($path)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true);

        return RebaseLog::fromArray($data);
    }

    /** @param array<int, SyncReport> $reports */
    public static function renderReports(array $reports): string
    {
        if ([] === $reports) {
            return 'no task worktrees found';
        }
        $lines = [];
        foreach ($reports as $report) {
            $icon = self::ACTION_ICONS[$report->action] ?? '•';
            $line = "{$icon} ".str_pad($report->branch, 24)." {$report->action}";
            if (\in_array($report->action, ['would-sync', 'synced'], true) && ($report->behind || $report->ahead)) {
                $line .= " (behind {$report->behind}, ahead {$report->ahead})";
            }
            if ('' !== $report->detail && 'conflict' !== $report->action) {
                $line .= ' — '.$report->detail;
            }
            $lines[] = $line;
            if ('conflict' === $report->action) {
                $lines[] = '   conflicting files: '.implode(', ', $report->conflictFiles);
                if ('' !== $report->agentHandle) {
                    $lines[] = '   fix agent running — '.SessionRef::command($report->agentHandle);
                }
            }
        }

        return implode("\n", $lines);
    }
}
