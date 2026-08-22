<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Support\ProcessRunner;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Launching OpenCode agents on worktrees and querying their activity.
 *
 * Primary path is the Orca CLI. When Orca refuses (e.g. selector_not_found
 * for an unregistered repo) or is unreachable, PABLO falls back to a headless
 * `opencode run` tracked via pidfiles under ~/.pablo/agents/.
 */
final class Agents extends AbstractAgentLauncher
{
    public const ORCA_CALL_TIMEOUT_S = 60;
    public const ORCA_ADOPT_WAIT_S = 15;
    public const RUNNING_STATES = ['working', 'running'];
    public const FINISHED_STATES = ['done', 'completed'];

    public function __construct(
        ?ProcessRunnerInterface $runner = null,
        ?Time $time = null,
        ?Store $store = null,
        ?string $shimPath = null,
    ) {
        parent::__construct($runner ?? new ProcessRunner(), $time ?? new Time(), $store, $shimPath, 'orca');
    }

    /** @return array{0: ?array, 1: ?string} (result, reason) */
    /**
     * @param list<string> $argv
     */
    private function orca(array $argv, float $perCallTimeout = self::ORCA_CALL_TIMEOUT_S): OrcaCall
    {
        $out = '';
        try {
            $out = $this->runner->run(['orca', ...$argv, '--json'], check: false, timeout: $perCallTimeout);
            $data = json_decode($out, true);
        } catch (\Throwable $e) {
            return new OrcaCall(null, $e::class.': '.$e->getMessage().'; raw_stdout='.var_export($out, true));
        }
        if (!\is_array($data) || ($data['ok'] ?? false) !== true) {
            return new OrcaCall(null, 'ok:false response: '.var_export($data, true));
        }

        return new OrcaCall(\is_array($data['result'] ?? null) ? $data['result'] : [], null);
    }

    private function logOrcaFallback(string $worktree, string $label, ?string $reason): void
    {
        $logs = $this->agentsDir().'/logs';
        if (!is_dir($logs)) {
            @mkdir($logs, 0o777, true);
        }
        file_put_contents(
            $logs.'/orca-fallback.log',
            $this->time->utcnow()." worktree={$worktree} label={$label} reason={$reason}\n",
            \FILE_APPEND,
        );
    }

    /**
     * Blocking per-worktree flock serialising `orca terminal create` spans.
     */
    private function withLaunchLock(string $worktree, callable $fn): mixed
    {
        $locks = $this->agentsDir().'/locks';
        if (!is_dir($locks)) {
            @mkdir($locks, 0o777, true);
        }
        $stem = preg_replace('/[^a-zA-Z0-9]/', '_', $worktree) ?: 'root';
        $fd = fopen($locks.'/'.$stem.'.launch.lock', 'w');
        if (false === $fd) {
            throw new \Pablo\Support\PabloError('cannot open launch lock: '.$locks.'/'.$stem.'.launch.lock');
        }
        try {
            flock($fd, \LOCK_EX);

            return $fn();
        } finally {
            flock($fd, \LOCK_UN);
            fclose($fd);
        }
    }

    /**
     * Orca discovers newly `git worktree add`'d branches of registered repos
     * asynchronously; a `terminal create` on a not-yet-adopted path fails with
     * `selector_not_found`. Poll `worktree show` (which fails fast) until Orca
     * adopts the worktree or $maxWaitS elapses.
     */
    private function waitForOrcaAdoption(string $worktree, int $maxWaitS = self::ORCA_ADOPT_WAIT_S): bool
    {
        $deadline = microtime(true) + $maxWaitS;
        while (microtime(true) < $deadline) {
            $call = $this->orca(['worktree', 'show', '--worktree', 'path:'.$worktree]);
            if (null !== $call->payload) {
                return true;
            }
            usleep(1_000_000);
        }

        return false;
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt): string
    {
        $command = 'opencode '.escapeshellarg($worktree)
            ." --agent {$agent} --prompt ".escapeshellarg($prompt);
        $call = $this->withLaunchLock($worktree, function () use ($worktree, $agent, $command) {
            if (!$this->waitForOrcaAdoption($worktree)) {
                return new OrcaCall(null, 'orca adoption timeout for '.$worktree);
            }

            return $this->orca([
                'terminal', 'create',
                '--worktree', 'path:'.$worktree,
                '--title', 'pablo:'.$agent,
                '--command', $command,
            ]);
        });
        $result = $call->payload;
        $reason = $call->reason;
        if (null !== $result) {
            $handle = $result['handle'] ?? $result['agentTerminalHandle'] ?? ($result['startupTerminal']['handle'] ?? null) ?? ($result['terminal']['handle'] ?? null);
            if (null !== $handle) {
                return (string) $handle;
            }
        }
        $this->logOrcaFallback($worktree, $agent, $reason);

        return $this->launchHeadless($worktree, $agent, $prompt);
    }

    public function doRunStartupScript(string $worktree, string $script): string
    {
        $command = 'bash '.escapeshellarg($script).'; exec bash';
        $call = $this->withLaunchLock($worktree, function () use ($worktree, $command) {
            if (!$this->waitForOrcaAdoption($worktree)) {
                return new OrcaCall(null, 'orca adoption timeout for '.$worktree);
            }

            return $this->orca([
                'terminal', 'create',
                '--worktree', 'path:'.$worktree,
                '--title', 'pablo:startup-script',
                '--command', $command,
            ]);
        });
        $result = $call->payload;
        $reason = $call->reason;
        if (null !== $result) {
            $handle = $result['handle'] ?? $result['agentTerminalHandle'] ?? ($result['startupTerminal']['handle'] ?? null) ?? ($result['terminal']['handle'] ?? null);
            if (null !== $handle) {
                return (string) $handle;
            }
        }
        $this->logOrcaFallback($worktree, 'startup-script', $reason);

        return $this->launchHeadlessCommand($worktree, 'startup-script', $command);
    }

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void
    {
        $this->orca([
            'worktree', 'set',
            '--worktree', 'path:'.$worktree,
            '--display-name', $name,
            '--issue', $issueNumber ?? 'null',
        ], perCallTimeout: self::ORCA_CALL_TIMEOUT_S);
    }

    /** @return array<string, array<int, SessionInfo>> */
    /**
     * @param list<array<string, mixed>> $orcaWorktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    private function sessionsByWorktreeFromOrca(array $orcaWorktrees, bool $includeFinishedAsWaiting = false): array
    {
        $grouped = [];
        foreach ($orcaWorktrees as $wt) {
            $path = (string) ($wt['path'] ?? '');
            foreach ($wt['agents'] ?? [] as $agent) {
                $state = $agent['state'] ?? null;
                if (\in_array($state, self::FINISHED_STATES, true)) {
                    if (!$includeFinishedAsWaiting) {
                        continue; // a finished agent (e.g. "done") must not count as an active session
                    }
                    // For the display/split path a finished analyst is parked
                    // awaiting the user (review the plan and /commit-and-pr), so
                    // it reports as waiting so the "💭 Waiting for feedback" split
                    // surfaces it. It still never blocks closure or relaunch,
                    // which keep using activeSessions() (this flag off).
                    $status = 'waiting';
                } else {
                    $status = \in_array($state, self::RUNNING_STATES, true) ? 'running' : 'waiting';
                }
                $grouped[$path][] = new SessionInfo((string) ($agent['paneKey'] ?? ''), $status);
            }
        }

        return $grouped;
    }

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array
    {
        return $this->sessionsForWorktree($worktree, false);
    }

    /** @param array<int, string> $worktrees @return array<string, array<int, SessionInfo>> */
    /**
     * @param list<string> $worktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array
    {
        return $this->bulkSessionsForWorktrees($worktrees, false);
    }

    /**
     * Active agents for display/rendering, counting a finished (orca "done")
     * analyst as waiting so the "💭 Waiting for feedback" split surfaces it.
     *
     * @return array<int, SessionInfo>
     */
    public function displaySessions(string $worktree): array
    {
        return $this->sessionsForWorktree($worktree, true);
    }

    /**
     * Bulk variant of displaySessions().
     *
     * @param list<string> $worktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    public function bulkDisplaySessions(array $worktrees): array
    {
        return $this->bulkSessionsForWorktrees($worktrees, true);
    }

    /** @return array<int, SessionInfo> */
    private function sessionsForWorktree(string $worktree, bool $includeFinishedAsWaiting): array
    {
        $result = $this->orca(['worktree', 'ps', '--limit', '200'])->payload;
        $orcaGrouped = null !== $result ? $this->sessionsByWorktreeFromOrca($result['worktrees'] ?? [], $includeFinishedAsWaiting) : [];
        $sessions = $orcaGrouped[$worktree] ?? [];
        $sessions = array_merge($sessions, $this->headlessSessionsByWorktree()[$worktree] ?? []);

        return $sessions;
    }

    /** @param list<string> $worktrees
     * @return array<string, list<SessionInfo>>
     */
    private function bulkSessionsForWorktrees(array $worktrees, bool $includeFinishedAsWaiting): array
    {
        $wanted = array_fill_keys($worktrees, true);
        $result = $this->orca(['worktree', 'ps', '--limit', '200'])->payload;
        $orcaGrouped = null !== $result ? $this->sessionsByWorktreeFromOrca($result['worktrees'] ?? [], $includeFinishedAsWaiting) : [];
        $headlessGrouped = $this->headlessSessionsByWorktree();
        $out = [];
        foreach ($wanted as $wt => $_) {
            $out[$wt] = array_merge($orcaGrouped[$wt] ?? [], $headlessGrouped[$wt] ?? []);
        }

        return $out;
    }

    public function hasAnyOrcaAgent(string $worktree): bool
    {
        $result = $this->orca(['worktree', 'ps', '--limit', '200'])->payload;
        if (null === $result) {
            return false;
        }
        foreach ($result['worktrees'] ?? [] as $wt) {
            if ($wt['path'] === $worktree && [] !== ($wt['agents'] ?? [])) {
                return true;
            }
        }

        return false;
    }

    protected function waitForBackendHandle(string $handle, int $timeoutS): void
    {
        try {
            $this->runner->run([
                'orca', 'terminal', 'wait', '--terminal', $handle,
                '--for', 'exit', '--timeout-ms', (string) ($timeoutS * 1000), '--json',
            ], check: false, timeout: $timeoutS + 120);
        } catch (\Throwable) {
            // orca gone/hung: treat the run as finished rather than wedging
        }
    }
}
