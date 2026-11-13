<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Agents\Runtime\SessionRef;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Store\TaskLockedException;
use Pablo\Support\Json;
use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;
use Symfony\Component\Process\Exception\ExceptionInterface as ProcessException;

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
        'local-rewrite' => '✏️',
        'locked' => '🔐',
        'skipped' => '⏭️',
        'error' => '❌',
    ];

    public function __construct(
        private readonly GitRepoInterface $git,
        private readonly GhPrInterface $gh,
        private readonly RepoSlug $repoSlug,
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time,
        private readonly AgentRuntimeRegistry $runtimes = new AgentRuntimeRegistry(),
        private readonly float $lockTimeoutS = self::SYNC_LOCK_TIMEOUT_S,
        private readonly SyncHistory $history = new SyncHistory(),
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
        foreach ($this->git->listWorktrees($cfg->repo->path) as $ref) {
            if ((string) realpath($ref->path) !== (string) realpath($cfg->repo->path)
                && $ref->branch !== $cfg->repo->primaryBranch) {
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
            if (null !== $base && '' !== $base && $base !== $cfg->repo->primaryBranch) {
                $baseByBranch[$branch] = $base;
            }
        }

        return $baseByBranch;
    }

    /** @return array<int, SyncReport> */
    public function syncProject(ProjectConfig $cfg, Store $store, ?bool $apply, ?AgentLauncherInterface $agents = null): array
    {
        $effectiveApply = $apply ?? $cfg->sync->autoApply;
        $reports = [];
        $branches = [];
        $byBranch = [];
        foreach ($this->discover($cfg) as $ref) {
            $path = $ref->path;
            $branch = $ref->branch;
            $task = '' === $branch ? null : $store->get($cfg->name, $branch);
            if (null === $task) {
                // Not (or no longer) a PABLO task worktree: leave it alone. Only
                // active tasks are kept in sync, so an orphaned or foreign
                // worktree is never rebased and never shows up in the log.
                continue;
            }
            if (State::Closed === $task->state) {
                // PR closed without merging: the worktree is only kept for a
                // last look, so it is never rebased nor force-pushed. Reported
                // so sync:log says why the task was left alone.
                $reports[] = new SyncReport(
                    worktree: $path,
                    branch: $branch,
                    action: 'skipped',
                    detail: 'pull request closed without merging; not synced',
                );
                continue;
            }
            $branches[] = $branch;
            $byBranch[$branch] = $path;
        }
        $stacked = $this->resolveStackedBases($cfg, $branches);
        foreach ($branches as $branch) {
            $path = $byBranch[$branch];
            try {
                $lock = $store->taskLock($cfg->name, $branch, $this->lockTimeoutS);
                try {
                    $reports[] = $this->git->syncWorktree(
                        $path,
                        $branch,
                        $cfg->repo->primaryBranch,
                        $cfg->sync->strategy,
                        $effectiveApply,
                        base: $stacked[$branch] ?? null,
                        remote: $cfg->repo->primaryRemote,
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
            } catch (PabloError|ProcessException $e) {
                // One broken worktree (e.g. deleted by hand but still listed by
                // git) must not stop the others from syncing, nor the log from
                // being written.
                $reports[] = new SyncReport(
                    worktree: $path,
                    branch: $branch,
                    action: 'error',
                    detail: $e->getMessage().'; will retry next cycle',
                );
            }
        }
        foreach ($reports as $report) {
            if ('conflict' === $report->action && $effectiveApply && null !== $agents) {
                $prompt = $this->buildConflictAgentPrompt($report, $cfg);
                // Most runtimes take a PABLO-assigned session id; one that
                // picks its own finds it back once the run started.
                $runtime = $this->runtimes->resolve();
                $sessionId = $runtime->newSessionId();
                $agents->launchHeadless($report->worktree, 'rebase-conflict-resolver', $prompt, $cfg->defaultModel, $sessionId);
                if (null === $sessionId) {
                    sleep(1);
                    $sessionId = $runtime->sessions()->findSession($this->runner, $report->worktree);
                }
                $report->agentHandle = null !== $sessionId ? $runtime->sessions()->handle($sessionId) : 'launched';
            }
        }
        $log = $this->saveLastLog($cfg->name, $cfg->sync->strategy, $reports);
        // Best-effort (never throws): a lost history line must not fail the sync.
        $this->history->append(new SyncRun($log, $effectiveApply));

        return $reports;
    }

    public static function logsDir(): string
    {
        return PabloPaths::pabloPath('logs', 'PABLO_LOGS_DIR');
    }

    /** @param list<SyncReport> $reports */
    public function saveLastLog(string $projectName, string $strategy, array $reports): RebaseLog
    {
        $path = self::logsDir()."/rebase-last-{$projectName}.json";
        $dir = \dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        $log = new RebaseLog($projectName, $this->time->utcnow(), $strategy, $reports);
        file_put_contents($path, json_encode($log->toArray(), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");

        return $log;
    }

    /**
     * Past sync runs of a project, newest first (see SyncHistory).
     *
     * @param list<string> $only
     *
     * @return list<SyncRun>
     */
    public function history(string $projectName, ?int $limit = null, array $only = []): array
    {
        return $this->history->read($projectName, $limit, $only);
    }

    /**
     * The resolver must rebase onto the exact ref the engine used
     * ($report->target): the PR's base when stacked, else the primary — and
     * the primary too when a stacked base no longer exists on the remote.
     */
    public function buildConflictAgentPrompt(SyncReport $report, ProjectConfig $cfg): string
    {
        $remote = $cfg->repo->primaryRemote;
        $primaryTarget = $remote.'/'.$cfg->repo->primaryBranch;
        $target = '' !== $report->target ? $report->target : $primaryTarget;
        $stacked = $target !== $primaryTarget && $target !== $cfg->repo->primaryBranch;
        $lines = [
            "PABLO sync hit rebase conflicts on branch `{$report->branch}`.",
            "Rebase onto `{$target}` using strategy `{$cfg->sync->strategy}` was aborted.",
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

    public function loadLastLog(string $projectName): ?RebaseLog
    {
        $path = self::logsDir()."/rebase-last-{$projectName}.json";
        if (!is_file($path)) {
            return null;
        }

        $data = Json::decodeArray((string) file_get_contents($path), "rebase log {$path}");

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
