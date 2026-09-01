<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;

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

    public function __construct(
        ProcessRunnerInterface $runner = new ProcessRunner(),
        Time $time = new Time(),
        ?Store $store = null,
        ?string $shimPath = null,
        ?string $agentsDir = null,
    ) {
        parent::__construct($runner, $time, $store, $shimPath, 'openchamber');
        $this->agentFilesDir = $agentsDir ?? \dirname(__DIR__, 3).'/opencode/agents';
    }

    private readonly string $agentFilesDir;

    /** @param list<string> $argv
     *
     * @return array<string, mixed>|null decoded JSON result, or null on CLI error
     */
    private function openchamber(array $argv, bool $check = false): ?array
    {
        try {
            $out = $this->runner->run(['openchamber', ...$argv, '--json'], check: $check, timeout: self::SESSION_TIMEOUT_S);
            $data = json_decode($out, true);
        } catch (\Throwable) {
            return null;
        }

        return \is_array($data) && ($data['status'] ?? null) === 'ok' ? $data : null;
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt): string
    {
        $model = $this->agentModel($agent);
        $create = [
            'session', 'create', '--dir', $worktree, '--title', 'pablo:'.$agent,
        ];
        if (null !== $model) {
            $create[] = '--model';
            $create[] = $model;
        }
        $created = $this->openchamber($create);
        $sessionId = $created['sessionId'] ?? null;
        if (!\is_string($sessionId) || '' === $sessionId) {
            return $this->launchHeadless($worktree, $agent, $prompt);
        }
        $this->writeSessionPidfile($sessionId, $worktree, $agent);
        $send = [
            'session', 'send', '--session', $sessionId, '--dir', $worktree,
            '--prompt', $prompt, '--agent', $agent,
        ];
        if (null !== $model) {
            $send[] = '--model';
            $send[] = $model;
        }
        $this->openchamber($send);

        return $sessionId;
    }

    /**
     * The model declared in the agent's frontmatter (`opencode/agents/<agent>.md`,
     * the same file `system:generate-agents` writes and `install.sh` symlinks into
     * ~/.config/opencode). OpenChamber otherwise falls back to its own
     * "configured selection", which can silently pick a different model than the
     * one the agent config declares.
     *
     * @return string|null null when the file or a `model:` line is missing
     */
    private function agentModel(string $agent): ?string
    {
        $path = $this->agentFilesDir.'/'.$agent.'.md';
        if (!is_file($path)) {
            return null;
        }
        $content = (string) file_get_contents($path);
        if (1 !== preg_match('/^---\R(.*?)\R---\R/su', $content, $m)) {
            return null;
        }
        if (1 !== preg_match('/^model:\s*(\S+)\s*$/m', $m[1], $model)) {
            return null;
        }

        return $model[1];
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
                'started_at' => $this->time->utcnow(),
            ]),
        );
    }

    /**
     * Raw sessions for the queried worktrees, discovered directly from the
     * OpenChamber daemon rather than from pidfiles: one `session list --dir`
     * call per worktree. Unlike Orca's central `worktree ps`, OpenChamber is
     * directory-scoped, so PABLO asks for each worktree it actually cares about.
     *
     * @param list<string> $worktrees
     *
     * @return array<string, list<array<string, mixed>>> worktree => raw sessions
     */
    private function sessionsByWorktree(array $worktrees): array
    {
        $out = [];
        foreach ($worktrees as $wt) {
            $list = $this->openchamber(['session', 'list', '--dir', $wt, '--with-status', '--limit', '200']);
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
        return $this->mapSessions($this->sessionsByWorktree([$worktree])[$worktree] ?? [], false);
    }

    /** @param array<int, string> $worktrees @return array<string, array<int, SessionInfo>> */
    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array
    {
        $byWt = $this->sessionsByWorktree($worktrees);
        $out = [];
        foreach ($worktrees as $wt) {
            $out[$wt] = $this->mapSessions($byWt[$wt] ?? [], false);
        }

        return $out;
    }

    /** @return array<int, SessionInfo> */
    public function displaySessions(string $worktree): array
    {
        return $this->mapSessions($this->sessionsByWorktree([$worktree])[$worktree] ?? [], true);
    }

    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    public function bulkDisplaySessions(array $worktrees): array
    {
        $byWt = $this->sessionsByWorktree($worktrees);
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
        return [] !== ($this->sessionsByWorktree([$worktree])[$worktree] ?? []);
    }

    protected function waitForBackendHandle(string $handle, int $timeoutS): void
    {
        $worktree = $this->worktreeForSession($handle);
        if (null === $worktree) {
            return;
        }
        try {
            $this->runner->run([
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
