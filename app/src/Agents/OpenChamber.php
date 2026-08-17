<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Support\Proc;

/**
 * OpenChamber agent backend.
 *
 * OpenChamber is directory-based (unlike Orca's workspace model): sessions are
 * bound to a --dir path, there is no repo registration, no worktree "adoption"
 * and no display-name concept. A launch creates a session in the task worktree
 * and dispatches a prompt to it; activity is read back via `session list`.
 *
 * Launch flow (both fire-and-forget, the detached watcher absorbs the async
 * land):
 *   openchamber session create --dir <wt> --title pablo:<agent>
 *   openchamber session send --session <id> --dir <wt> --prompt <p> --agent <a>
 *
 * Status mapping (OpenChamber `sessionStatus.type`):
 *   busy/retry -> running; idle -> waiting (display) / excluded (active).
 */
final class OpenChamber extends AbstractAgentLauncher
{
    public const SESSION_TIMEOUT_S = 60;

    public function __construct(?string $shimPath = null)
    {
        parent::__construct($shimPath, 'openchamber');
    }

    /** @param list<string> $argv
     *
     * @return array<string, mixed>|null decoded JSON result, or null on CLI error
     */
    private function openchamber(array $argv, bool $check = false): ?array
    {
        try {
            $out = Proc::run(['openchamber', ...$argv, '--json'], check: $check, timeout: self::SESSION_TIMEOUT_S);
            $data = json_decode($out, true);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($data) && ($data['status'] ?? null) === 'ok' ? $data : null;
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt): string
    {
        $created = $this->openchamber([
            'session', 'create', '--dir', $worktree, '--title', 'pablo:'.$agent,
        ]);
        $sessionId = $created['sessionId'] ?? null;
        if (!\is_string($sessionId) || '' === $sessionId) {
            return $this->launchHeadless($worktree, $agent, $prompt);
        }
        $this->writeSessionPidfile($sessionId, $worktree, $agent);
        $this->openchamber([
            'session', 'send', '--session', $sessionId, '--dir', $worktree,
            '--prompt', $prompt, '--agent', $agent,
        ]);

        return $sessionId;
    }

    public function doRunStartupScript(string $worktree, string $script): string
    {
        $command = 'bash '.escapeshellarg($script).'; exec bash';

        return $this->launchHeadlessCommand($worktree, 'startup-script', $command);
    }

    private function writeSessionPidfile(string $sessionId, string $worktree, string $agent): void
    {
        $dir = $this->agentsDir();
        if (!is_dir($dir)) {
            @mkdir($dir, 0o777, true);
        }
        file_put_contents(
            $dir.'/openchamber-'.$sessionId.'.json',
            json_encode([
                'session' => $sessionId,
                'worktree' => $worktree,
                'agent' => $agent,
                'started_at' => \Pablo\Domain\Time::utcnow(),
            ]),
        );
    }

    /** @return array<string, list<array<string, mixed>>> worktree => raw sessions */
    private function sessionsByWorktree(): array
    {
        $out = [];
        foreach (glob($this->agentsDir().'/openchamber-*.json') ?: [] as $file) {
            $record = json_decode((string) file_get_contents($file), true);
            if (!\is_array($record) || !isset($record['worktree'])) {
                @unlink($file);
                continue;
            }
            $wt = (string) $record['worktree'];
            $list = $this->openchamber(['session', 'list', '--dir', $wt, '--with-status']);
            foreach ($list['sessions'] ?? [] as $s) {
                $out[$wt][] = $s;
            }
        }

        return $out;
    }

    /**
     * @param list<array<string, mixed>> $rawSessions
     *
     * @return list<SessionInfo>
     */
    private function mapSessions(array $rawSessions, bool $includeIdleAsWaiting): array
    {
        $result = [];
        foreach ($rawSessions as $s) {
            $status = $s['status']['type'] ?? 'idle';
            $id = (string) ($s['id'] ?? '');
            if ('busy' === $status || 'retry' === $status) {
                $result[] = new SessionInfo($id, 'running');
            } elseif ($includeIdleAsWaiting) {
                // An idle session has finished its run; in display mode it
                // parks as waiting (review the plan, commit-and-PR).
                $result[] = new SessionInfo($id, 'waiting');
            }
        }

        return $result;
    }

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array
    {
        return $this->mapSessions($this->sessionsByWorktree()[$worktree] ?? [], false);
    }

    /** @param array<int, string> $worktrees @return array<string, array<int, SessionInfo>> */
    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array
    {
        $byWt = $this->sessionsByWorktree();
        $out = [];
        foreach ($worktrees as $wt) {
            $out[$wt] = $this->mapSessions($byWt[$wt] ?? [], false);
        }

        return $out;
    }

    /** @return array<int, SessionInfo> */
    public function displaySessions(string $worktree): array
    {
        return $this->mapSessions($this->sessionsByWorktree()[$worktree] ?? [], true);
    }

    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    public function bulkDisplaySessions(array $worktrees): array
    {
        $byWt = $this->sessionsByWorktree();
        $out = [];
        foreach ($worktrees as $wt) {
            $out[$wt] = $this->mapSessions($byWt[$wt] ?? [], true);
        }

        return $out;
    }

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void
    {
        // OpenChamber has no workspace display-name concept; nothing to set.
    }

    public function hasAnyOrcaAgent(string $worktree): bool
    {
        return [] !== ($this->sessionsByWorktree()[$worktree] ?? []);
    }

    protected function waitForBackendHandle(string $handle, int $timeoutS): void
    {
        $worktree = $this->worktreeForSession($handle);
        if (null === $worktree) {
            return;
        }
        try {
            Proc::run([
                'openchamber', 'session', 'messages',
                '--session', $handle, '--dir', $worktree,
                '--wait', '--timeout', (string) max(1, $timeoutS),
                '--json',
            ], check: false, timeout: $timeoutS + 60);
        } catch (\Throwable) {
            // openchamber gone/hung: treat the run as finished rather than wedging
        }
        $this->cleanupSessionFile($handle);
    }

    private function worktreeForSession(string $sessionId): ?string
    {
        $path = $this->agentsDir().'/openchamber-'.$sessionId.'.json';
        if (!is_file($path)) {
            return null;
        }
        $record = json_decode((string) file_get_contents($path), true);

        return \is_array($record) && isset($record['worktree']) ? (string) $record['worktree'] : null;
    }

    private function cleanupSessionFile(string $sessionId): void
    {
        @unlink($this->agentsDir().'/openchamber-'.$sessionId.'.json');
    }
}
