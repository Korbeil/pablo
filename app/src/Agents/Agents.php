<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Domain\DisplayCache;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Support\Proc;

/**
 * Launching OpenCode agents on worktrees and querying their activity.
 *
 * Primary path is the Orca CLI. When Orca refuses (e.g. selector_not_found
 * for an unregistered repo) or is unreachable, PABLO falls back to a headless
 * `opencode run` tracked via pidfiles under ~/.pablo/agents/.
 *
 * launch()/runStartupScript() each spawn a detached `pablo
 * internal-launch-agent` / `internal-run-startup-script` subprocess and return
 * immediately (orca can hang outside an interactive session). The actual
 * Orca/headless work happens in doLaunchAgent()/doRunStartupScript(), which
 * only ever run inside that detached subprocess.
 */
final class Agents implements AgentLauncherInterface
{
    public const ORCA_WAIT_TIMEOUT_MS = 3_600_000;
    public const ORCA_CALL_TIMEOUT_S = 60;
    public const RUNNING_STATES = ['working', 'running'];

    private string $agentsDir;

    /** @param string $shimPath absolute path used for detached self-reinvocation */
    public function __construct(private string $shimPath)
    {
        $override = getenv('PABLO_AGENTS_DIR');
        $this->agentsDir = (false !== $override && '' !== $override)
            ? $override
            : (getenv('HOME') ?: '~').'/.pablo/agents';
    }

    public function agentsDir(): string
    {
        return $this->agentsDir;
    }

    /** @return array{0: ?array, 1: ?string} (result, reason) */
    /**
     * @param list<string> $argv
     *
     * @return array{0: ?array<string, mixed>, 1: ?string} (result, reason)
     */
    private function orca(array $argv, float $perCallTimeout = self::ORCA_CALL_TIMEOUT_S): array
    {
        $out = '';
        try {
            $out = Proc::run(['orca', ...$argv, '--json'], check: false, timeout: $perCallTimeout);
            $data = json_decode($out, true);
        } catch (\Throwable $e) {
            return [null, $e::class.': '.$e->getMessage().'; raw_stdout='.var_export($out, true)];
        }
        if (!\is_array($data) || ($data['ok'] ?? false) !== true) {
            return [null, 'ok:false response: '.var_export($data, true)];
        }

        return [$data['result'] ?? [], null];
    }

    private function logOrcaFallback(string $worktree, string $label, ?string $reason): void
    {
        $logs = $this->agentsDir.'/logs';
        @mkdir($logs, 0o777, true);
        file_put_contents(
            $logs.'/orca-fallback.log',
            Time::utcnow()." worktree={$worktree} label={$label} reason={$reason}\n",
            \FILE_APPEND,
        );
    }

    /**
     * Blocking per-worktree flock serialising `orca terminal create` spans.
     */
    private function withLaunchLock(string $worktree, callable $fn): mixed
    {
        $locks = $this->agentsDir.'/locks';
        @mkdir($locks, 0o777, true);
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

    private function logFor(string $label): string
    {
        $logs = $this->agentsDir.'/logs';
        @mkdir($logs, 0o777, true);

        return $logs.'/'.time().'-'.$label.'.log';
    }

    private function writePidfile(int $pid, string $worktree, string $label): void
    {
        file_put_contents(
            $this->agentsDir.'/'.$pid.'.json',
            json_encode([
                'pid' => $pid,
                'worktree' => $worktree,
                'agent' => $label,
                'started_at' => Time::utcnow(),
            ]),
        );
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt): string
    {
        $command = 'opencode '.escapeshellarg($worktree)
            ." --agent {$agent} --prompt ".escapeshellarg($prompt);
        [$result, $reason] = $this->withLaunchLock($worktree, function () use ($worktree, $agent, $command) {
            return $this->orca([
                'terminal', 'create',
                '--worktree', 'path:'.$worktree,
                '--title', 'pablo:'.$agent,
                '--command', $command,
            ]);
        });
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
        [$result, $reason] = $this->withLaunchLock($worktree, function () use ($worktree, $command) {
            return $this->orca([
                'terminal', 'create',
                '--worktree', 'path:'.$worktree,
                '--title', 'pablo:startup-script',
                '--command', $command,
            ]);
        });
        if (null !== $result) {
            $handle = $result['handle'] ?? $result['agentTerminalHandle'] ?? ($result['startupTerminal']['handle'] ?? null) ?? ($result['terminal']['handle'] ?? null);
            if (null !== $handle) {
                return (string) $handle;
            }
        }
        $this->logOrcaFallback($worktree, 'startup-script', $reason);

        return $this->launchHeadlessCommand($worktree, 'startup-script', $command);
    }

    /** Spawn a detached process via setsid and capture its pid. */
    /**
     * @param list<string> $argv
     */
    private function spawnDetached(array $argv): int
    {
        $cmd = 'setsid '.implode(' ', array_map(static fn ($a) => escapeshellarg((string) $a), $argv))
            .' >/dev/null 2>&1 & echo $!';

        return (int) trim((string) shell_exec($cmd));
    }

    public function launch(string $worktree, \Pablo\Domain\Agent $agent, string $prompt, string $project, string $branch): string
    {
        $pid = $this->spawnDetached([
            $this->shimPath, 'internal-launch-agent',
            '--worktree', $worktree,
            '--agent', $agent->value,
            '--prompt', $prompt,
            '--project', $project,
            '--branch', $branch,
        ]);

        return "pid:{$pid}";
    }

    public function runStartupScript(string $worktree, string $script, string $project, string $branch): string
    {
        $pid = $this->spawnDetached([
            $this->shimPath, 'internal-run-startup-script',
            '--worktree', $worktree,
            '--script', $script,
            '--project', $project,
            '--branch', $branch,
        ]);

        return "pid:{$pid}";
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

    public function refreshAgentDisplayCache(string $project, string $branch, string $worktree): void
    {
        try {
            $sessions = $this->activeSessions($worktree);
            $parts = [];
            $running = \count(array_filter($sessions, static fn ($s) => 'running' === $s->status));
            $waiting = \count(array_filter($sessions, static fn ($s) => 'waiting' === $s->status));
            if ($running) {
                $parts[] = "🏃 {$running}";
            }
            if ($waiting) {
                $parts[] = "💭 {$waiting}";
            }
            $activity = [] !== $parts ? implode(' · ', $parts) : '-';

            $store = new Store(Store::defaultRoot());
            $lock = Store::taskLock($store, $project, $branch, timeoutS: 2);
            try {
                $task = $store->get($project, $branch);
                if (null === $task) {
                    return;
                }
                $task->displayCache = new DisplayCache(
                    trackerStatus: $task->displayCache->trackerStatus,
                    prState: $task->displayCache->prState,
                    agentCount: \count($sessions),
                    agentActivity: $activity,
                    at: Time::utcnow(),
                );
                $store->save($task);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    private function launchHeadlessCommand(string $worktree, string $label, string $command): string
    {
        $log = $this->logFor($label);
        $out = [];
        exec('setsid bash -c '.$command.' >>'.escapeshellarg($log).' 2>&1 & echo $!', $out);
        $pid = (int) trim((string) ($out[0] ?? ''));
        $this->writePidfile($pid, $worktree, $label);

        return "pid:{$pid}";
    }

    public function launchHeadless(string $worktree, string $agent, string $prompt): string
    {
        $log = $this->logFor($agent);
        $out = [];
        exec(
            'setsid opencode run --agent '.escapeshellarg($agent)
            .' --dir '.escapeshellarg($worktree).' '.escapeshellarg($prompt)
            .' >>'.escapeshellarg($log).' 2>&1 & echo $!',
            $out,
        );
        $pid = (int) trim((string) ($out[0] ?? ''));
        $this->writePidfile($pid, $worktree, $agent);

        return "pid:{$pid}";
    }

    /** @return array<string, array<int, SessionInfo>> */
    /**
     * @param list<array<string, mixed>> $orcaWorktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    private function sessionsByWorktreeFromOrca(array $orcaWorktrees): array
    {
        $grouped = [];
        foreach ($orcaWorktrees as $wt) {
            $path = (string) ($wt['path'] ?? '');
            foreach ($wt['agents'] ?? [] as $agent) {
                $status = \in_array($agent['state'] ?? null, self::RUNNING_STATES, true) ? 'running' : 'waiting';
                $grouped[$path][] = new SessionInfo((string) ($agent['paneKey'] ?? ''), $status);
            }
        }

        return $grouped;
    }

    /** @return array<string, array<int, SessionInfo>> */
    private function headlessSessionsByWorktree(): array
    {
        $grouped = [];
        if (!is_dir($this->agentsDir)) {
            return $grouped;
        }
        foreach (glob($this->agentsDir.'/*.json') ?: [] as $pidfile) {
            $record = json_decode((string) file_get_contents($pidfile), true);
            if (!\is_array($record)) {
                @unlink($pidfile);
                continue;
            }
            $pid = $record['pid'] ?? null;
            if (null === $pid || !$this->pidAlive((int) $pid)) {
                @unlink($pidfile);
                continue;
            }
            // A headless run has no idle signal; it counts as running.
            $grouped[(string) ($record['worktree'] ?? '')][] = new SessionInfo("pid:{$pid}", 'running');
        }

        return $grouped;
    }

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array
    {
        [$result] = $this->orca(['worktree', 'ps', '--limit', '200']);
        $orcaGrouped = null !== $result ? $this->sessionsByWorktreeFromOrca($result['worktrees'] ?? []) : [];
        $sessions = $orcaGrouped[$worktree] ?? [];
        $sessions = array_merge($sessions, $this->headlessSessionsByWorktree()[$worktree] ?? []);

        return $sessions;
    }

    /** @param array<int, string> $worktrees @return array<string, array<int, SessionInfo>> */
    /**
     * @param list<string> $worktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    public function bulkActiveSessions(array $worktrees): array
    {
        $wanted = array_fill_keys($worktrees, true);
        [$result] = $this->orca(['worktree', 'ps', '--limit', '200']);
        $orcaGrouped = null !== $result ? $this->sessionsByWorktreeFromOrca($result['worktrees'] ?? []) : [];
        $headlessGrouped = $this->headlessSessionsByWorktree();
        $out = [];
        foreach ($wanted as $wt => $_) {
            $out[$wt] = array_merge($orcaGrouped[$wt] ?? [], $headlessGrouped[$wt] ?? []);
        }

        return $out;
    }

    public function pidAlive(int $pid): bool
    {
        return @posix_kill($pid, 0);
    }

    public function waitForHandle(string $handle, int $timeoutS = self::ORCA_WAIT_TIMEOUT_MS / 1000): void
    {
        if (str_starts_with($handle, 'pid:')) {
            $pid = (int) substr($handle, \strlen('pid:'));
            $deadline = microtime(true) + $timeoutS;
            while ($this->pidAlive($pid) && microtime(true) < $deadline) {
                sleep(5);
            }

            return;
        }
        try {
            Proc::run([
                'orca', 'terminal', 'wait', '--terminal', $handle,
                '--for', 'exit', '--timeout-ms', (string) ($timeoutS * 1000), '--json',
            ], check: false, timeout: $timeoutS + 120);
        } catch (\Throwable) {
            // orca gone/hung: treat the run as finished rather than wedging
        }
    }

    public function spawnWatcher(string $project, string $branch, string $handle, string $then, ?string $expectState = null): void
    {
        $argv = [
            $this->shimPath, 'watch-agent',
            '--project', $project, '--branch', $branch,
            '--handle', $handle, '--then', $then,
        ];
        if (null !== $expectState) {
            $argv[] = '--expect-state';
            $argv[] = $expectState;
        }
        $this->spawnDetached($argv);
    }
}
