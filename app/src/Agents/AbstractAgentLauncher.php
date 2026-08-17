<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Domain\DisplayCache;
use Pablo\Domain\Time;
use Pablo\Store\Store;

/**
 * Shared plumbing for agent backends (Orca, OpenChamber, headless).
 *
 * A backend implements the launching/activity-mapping half of
 * AgentLauncherInterface; this base owns everything backend-agnostic:
 * the detached self-reinvocation machinery, pidfile tracking, the spawned
 * watcher, the display-cache refresh and the pid branch of waitForHandle().
 *
 * launch()/runStartupScript() each spawn a detached `pablo
 * internal-launch-agent` / `internal-run-startup-script` subprocess and return
 * immediately (orca/openchamber can hang outside an interactive session). The
 * actual backend work happens in doLaunchAgent()/doRunStartupScript(), which
 * only ever run inside that detached subprocess.
 */
abstract class AbstractAgentLauncher implements AgentLauncherInterface
{
    private string $agentsDir;

    private readonly string $shimPath;

    protected readonly string $backend;

    /**
     * Mirrors Store::defaultRoot(): resolved per process, never at container
     * compile time (the compiled container is cached under app/var/cache).
     */
    public static function defaultShimPath(): string
    {
        $override = getenv('PABLO_SHIM');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return (getenv('HOME') ?: '~').'/.local/bin/pablo';
    }

    /** @param string|null $shimPath absolute path used for detached self-reinvocation */
    public function __construct(?string $shimPath = null, ?string $backend = null)
    {
        $this->shimPath = $shimPath ?? self::defaultShimPath();
        $this->backend = $backend ?? 'orca';
        $override = getenv('PABLO_AGENTS_DIR');
        $this->agentsDir = (false !== $override && '' !== $override)
            ? $override
            : (getenv('HOME') ?: '~').'/.pablo/agents';
    }

    public function agentsDir(): string
    {
        return $this->agentsDir;
    }

    protected function backendName(): string
    {
        return $this->backend;
    }

    /**
     * Build a detached background command that closes every inherited fd > 2
     * before exec, so a long-lived agent process never keeps the global
     * dispatch flock (or any other parent fd) alive after the run ends. bash
     * closes already-closed fds gracefully, unlike POSIX sh.
     */
    protected static function detach(string $inner): string
    {
        $close = implode('; ', array_map(static fn (int $n) => "exec {$n}>&-", range(3, 255)));

        return 'setsid bash -c '.escapeshellarg($close.'; exec '.$inner).' </dev/null >/dev/null 2>&1 & echo $!';
    }

    /** Spawn a detached process via setsid and capture its pid. */
    /**
     * @param list<string> $argv
     */
    protected function spawnDetached(array $argv): int
    {
        $inner = implode(' ', array_map(static fn ($a) => escapeshellarg((string) $a), $argv));

        return (int) trim((string) shell_exec(self::detach($inner)));
    }

    protected function logFor(string $label): string
    {
        $logs = $this->agentsDir.'/logs';
        if (!is_dir($logs)) {
            @mkdir($logs, 0o777, true);
        }

        return $logs.'/'.time().'-'.$label.'.log';
    }

    protected function writePidfile(int $pid, string $worktree, string $label): void
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

    public function launch(string $worktree, \Pablo\Domain\Agent $agent, string $prompt, string $project, string $branch): string
    {
        $pid = $this->spawnDetached([
            $this->shimPath, 'internal:launch-agent',
            '--backend', $this->backend,
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
            $this->shimPath, 'internal:run-startup-script',
            '--backend', $this->backend,
            '--worktree', $worktree,
            '--script', $script,
            '--project', $project,
            '--branch', $branch,
        ]);

        return "pid:{$pid}";
    }

    public function spawnWatcher(string $project, string $branch, string $handle, string $agent, string $then, ?string $expectState = null): void
    {
        $argv = [
            $this->shimPath, 'internal:watch-agent',
            '--backend', $this->backend,
            '--project', $project, '--branch', $branch,
            '--handle', $handle, '--agent', $agent,
            '--then', $then,
        ];
        if (null !== $expectState) {
            $argv[] = '--expect-state';
            $argv[] = $expectState;
        }
        $this->spawnDetached($argv);
    }

    public function refreshAgentDisplayCache(string $project, string $branch, string $worktree): void
    {
        try {
            $sessions = $this->displaySessions($worktree);
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

            $store = new Store();
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

    protected function launchHeadlessCommand(string $worktree, string $label, string $command): string
    {
        $log = $this->logFor($label);
        $out = [];
        exec(self::detach($command.' >>'.escapeshellarg($log).' 2>&1'), $out);
        $pid = (int) trim((string) ($out[0] ?? ''));
        $this->writePidfile($pid, $worktree, $label);

        return "pid:{$pid}";
    }

    public function launchHeadless(string $worktree, string $agent, string $prompt): string
    {
        $log = $this->logFor($agent);
        $out = [];
        exec(
            self::detach('opencode run --agent '.escapeshellarg($agent)
                .' --dir '.escapeshellarg($worktree).' '.escapeshellarg($prompt)
                .' >>'.escapeshellarg($log).' 2>&1'),
            $out,
        );
        $pid = (int) trim((string) ($out[0] ?? ''));
        $this->writePidfile($pid, $worktree, $agent);

        return "pid:{$pid}";
    }

    public function pidAlive(int $pid): bool
    {
        return @posix_kill($pid, 0);
    }

    /**
     * @return array<string, list<SessionInfo>>
     */
    protected function headlessSessionsByWorktree(): array
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

    public const ORCA_WAIT_TIMEOUT_MS = 3_600_000;

    public function waitForHandle(string $handle, int $timeoutS = 0): void
    {
        if (0 === $timeoutS) {
            $timeoutS = self::ORCA_WAIT_TIMEOUT_MS / 1000;
        }
        if (str_starts_with($handle, 'pid:')) {
            $pid = (int) substr($handle, \strlen('pid:'));
            $deadline = microtime(true) + $timeoutS;
            while ($this->pidAlive($pid) && microtime(true) < $deadline) {
                sleep(5);
            }

            return;
        }
        $this->waitForBackendHandle($handle, $timeoutS);
    }

    /**
     * Wait for a backend-specific handle (e.g. an Orca terminal or an
     * OpenChamber session id) to finish. Only ever called for non-pid handles.
     */
    abstract protected function waitForBackendHandle(string $handle, int $timeoutS): void;
}
