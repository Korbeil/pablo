<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Support\ProcessRunnerInterface;

/**
 * OpenCode picks its own session ids (`ses_…`); PABLO finds a just-launched
 * run's session back from `opencode session list`.
 */
final class OpenCodeSessions implements RuntimeSessionsInterface
{
    private const PREFIX = 'ses_';

    public function handle(string $sessionId): string
    {
        return $sessionId;
    }

    public function owns(string $handle): bool
    {
        return str_starts_with($handle, self::PREFIX);
    }

    public function resumeCommand(string $handle): string
    {
        return 'opencode -s '.$handle;
    }

    /**
     * The most recent session of $worktree. OpenCode v2 removed the `db`
     * subcommand (this used to query the sqlite store directly, filtered by
     * agent); `session list` only exposes a directory — the worktree's newest
     * session is a right-enough hint for the "fix agent running" display,
     * since the resolver runs right after the conflict and nothing else is
     * concurrent there.
     */
    public function findSession(ProcessRunnerInterface $runner, string $worktree): ?string
    {
        $probe = $runner->probe([
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
}
