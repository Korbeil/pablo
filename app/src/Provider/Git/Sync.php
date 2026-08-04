<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Store\TaskLockedException;
use Pablo\Support\Proc;

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

    /**
     * Task worktrees: git worktree list minus the primary checkout.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private static function discover(ProjectConfig $cfg): array
    {
        $out = [];
        foreach (GitRepo::listWorktrees($cfg->repoPath) as [$path, $branch]) {
            if ((string) realpath($path) !== (string) realpath($cfg->repoPath)
                && $branch !== $cfg->primaryBranch) {
                $out[] = [$path, $branch];
            }
        }

        return $out;
    }

    /** @return array<int, SyncReport> */
    public static function syncProject(ProjectConfig $cfg, Store $store, ?bool $apply, ?AgentLauncherInterface $agents = null): array
    {
        $effectiveApply = $apply ?? $cfg->syncAutoApply;
        $reports = [];
        foreach (self::discover($cfg) as [$path, $branch]) {
            if ('' === $branch) {
                $reports[] = new SyncReport(
                    worktree: $path,
                    branch: '?',
                    action: 'unregistered',
                    detail: 'directory under worktrees_root is not a worktree of the repo',
                );
                continue;
            }
            $task = $store->get($cfg->name, $branch);
            if (null !== $task) {
                try {
                    $lock = Store::taskLock($store, $cfg->name, $branch, self::SYNC_LOCK_TIMEOUT_S);
                    try {
                        $reports[] = GitRepo::syncWorktree(
                            $path,
                            $branch,
                            $cfg->primaryBranch,
                            $cfg->syncStrategy,
                            $effectiveApply,
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
            } else {
                $reports[] = GitRepo::syncWorktree(
                    $path,
                    $branch,
                    $cfg->primaryBranch,
                    $cfg->syncStrategy,
                    $effectiveApply,
                );
            }
        }
        foreach ($reports as $report) {
            if ('conflict' === $report->action && $effectiveApply && null !== $agents) {
                $prompt = self::buildConflictAgentPrompt($report, $cfg);
                $agents->launchHeadless($report->worktree, 'rebase-conflict-resolver', $prompt);
                sleep(1);
                $sessionId = self::findRecentSession($report->worktree, 'rebase-conflict-resolver');
                $report->agentHandle = $sessionId ?? 'launched';
            }
        }
        self::saveLastLog($cfg->name, $cfg->syncStrategy, $reports);

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

    /** @param array<int, SyncReport> $reports */
    public static function saveLastLog(string $projectName, string $strategy, array $reports): void
    {
        $path = self::logsDir()."/rebase-last-{$projectName}.json";
        @mkdir(\dirname($path), 0o777, true);
        $payload = [
            'timestamp' => Time::utcnow(),
            'project' => $projectName,
            'strategy' => $strategy,
            'reports' => array_map(static function (SyncReport $r) {
                return [
                    'branch' => $r->branch,
                    'action' => $r->action,
                    'behind' => $r->behind,
                    'ahead' => $r->ahead,
                    'conflict_files' => $r->conflictFiles,
                    'detail' => $r->detail,
                    'agent_handle' => $r->agentHandle,
                ];
            }, $reports),
        ];
        file_put_contents($path, json_encode($payload, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n");
    }

    public static function buildConflictAgentPrompt(SyncReport $report, ProjectConfig $cfg): string
    {
        $target = 'origin/'.$cfg->primaryBranch;
        $lines = [
            "PABLO sync hit rebase conflicts on branch `{$report->branch}`.",
            "Rebase onto `{$target}` using strategy `{$cfg->syncStrategy}` was aborted.",
            'The worktree is clean — you must re-run the rebase yourself.',
            '',
            '1. `git fetch --prune origin`',
            "2. If `origin/{$report->branch}` has new commits, rebase onto it first.",
            "3. `git rebase {$target}`",
            '4. Resolve every conflict. `git add` resolved files, `git rebase --continue`.',
            '5. Repeat until the rebase completes cleanly.',
            "6. `git push --force-with-lease origin {$report->branch}`",
            '',
            'Conflicting files from the original attempt:',
        ];
        foreach ($report->conflictFiles as $f) {
            $lines[] = "  - {$f}";
        }
        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * Returns the most recent opencode session id for the agent on $worktree,
     * or null. The worktree path is single-quote-escaped before interpolation
     * into the SQL string (the port fixes Python's unescaped bug).
     */
    private static function findRecentSession(string $worktree, string $agent): ?string
    {
        try {
            $escaped = str_replace("'", "''", $worktree);
            $result = Proc::run([
                'opencode', 'db',
                "SELECT id FROM session WHERE directory = '{$escaped}'"
                ." AND agent = '{$agent}'"
                .' ORDER BY time_created DESC LIMIT 1',
                '--format', 'json',
            ], check: false, timeout: 5);
            $rows = json_decode($result, true);
            if (\is_array($rows) && [] !== $rows && isset($rows[0]['id'])) {
                return (string) $rows[0]['id'];
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    public static function loadLastLog(string $projectName): ?array
    {
        $path = self::logsDir()."/rebase-last-{$projectName}.json";
        if (!is_file($path)) {
            return null;
        }

        /** @var array<string, mixed> $data */
        $data = json_decode((string) file_get_contents($path), true);

        return $data;
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
                    $lines[] = '   fix agent running — opencode -s '.$report->agentHandle;
                }
            }
        }

        return implode("\n", $lines);
    }
}
