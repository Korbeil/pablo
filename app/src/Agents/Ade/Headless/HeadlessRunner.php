<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Headless;

use Pablo\Agents\Ade\Detached;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\SessionInfo;
use Pablo\Domain\Time;
use Pablo\Support\Json;
use Pablo\Support\PabloPaths;

/**
 * Headless runs: the runtime's command detached in the background, tracked
 * via pidfiles (+ logs) under ~/.pablo/agents/. The Headless ADE's whole
 * job, and the fallback of every other ADE when it cannot launch — so each
 * launcher owns one (AbstractAgentLauncher::$headless). Handles are `pid:N`.
 */
final class HeadlessRunner
{
    public const HANDLE_PREFIX = 'pid:';

    private readonly string $agentsDir;

    public function __construct(
        private readonly Time $time,
        private readonly AgentRuntimeInterface $runtime,
    ) {
        // Resolved per process, never at container compile time.
        $this->agentsDir = PabloPaths::pabloPath('agents', 'PABLO_AGENTS_DIR');
    }

    public function agentsDir(): string
    {
        return $this->agentsDir;
    }

    public function logFor(string $label): string
    {
        $logs = $this->agentsDir.'/logs';
        if (!is_dir($logs)) {
            @mkdir($logs, 0o777, true);
        }

        return $logs.'/'.time().'-'.$label.'.log';
    }

    /**
     * Run $agent on $prompt in $worktree. $sessionId: a session id assigned
     * up front; null lets the runtime pick one.
     */
    public function launch(string $worktree, string $agent, string $prompt, ?string $model = null, ?string $sessionId = null): string
    {
        $sessionId ??= $this->runtime->newSessionId();
        // Runtimes attach the session to the working directory (OpenCode v2
        // removed `run --dir`), so the detached subshell cd's into the
        // worktree before exec'ing the agent CLI (and never runs it elsewhere).
        $log = $this->logFor($agent);
        $pid = $this->exec(
            'cd '.escapeshellarg($worktree).' && exec '.$this->runtime->launchCommand($agent, $prompt, $model, $sessionId, $worktree),
            $log,
        );
        $this->writePidfile($pid, $worktree, $agent, $log, $sessionId);

        return self::HANDLE_PREFIX.$pid;
    }

    /** Run an arbitrary shell $command (e.g. a startup script) from $worktree. */
    public function launchCommand(string $worktree, string $label, string $command): string
    {
        // Like launch(): never from the caller's cwd (the user's shell for
        // task:start, the scheduler's for a relaunch).
        $log = $this->logFor($label);
        $pid = $this->exec('cd '.escapeshellarg($worktree).' && '.$command, $log);
        $this->writePidfile($pid, $worktree, $label, $log);

        return self::HANDLE_PREFIX.$pid;
    }

    private function exec(string $command, string $log): int
    {
        $out = [];
        // The group's redirection covers every command in it, `cd` included,
        // and survives the final exec.
        exec(Detached::command('{ '.$command.'; } >>'.escapeshellarg($log).' 2>&1'), $out);

        return (int) trim((string) ($out[0] ?? ''));
    }

    /** The log path is recorded so `system:prune` never removes a live run's log. */
    private function writePidfile(int $pid, string $worktree, string $label, string $log, ?string $sessionId = null): void
    {
        $record = [
            'pid' => $pid,
            'worktree' => $worktree,
            'agent' => $label,
            'log' => $log,
            'started_at' => $this->time->utcnow(),
        ];
        if (null !== $sessionId) {
            $record['runtime'] = $this->runtime->name();
            $record['session'] = $sessionId;
        }
        file_put_contents($this->agentsDir.'/'.$pid.'.json', json_encode($record));
    }

    public function pidAlive(int $pid): bool
    {
        if (\function_exists('posix_kill')) {
            return @posix_kill($pid, 0);
        }

        return self::killZero($pid);
    }

    /** `kill -0 <pid>`: pidAlive() without ext-posix. */
    private static function killZero(int $pid): bool
    {
        exec('kill -0 '.$pid.' 2>/dev/null', $output, $code);

        return 0 === $code;
    }

    /**
     * Live headless runs by worktree; dead pidfiles are swept on the way.
     *
     * @return array<string, list<SessionInfo>>
     */
    public function sessionsByWorktree(): array
    {
        $grouped = [];
        if (!is_dir($this->agentsDir)) {
            return $grouped;
        }
        foreach (glob($this->agentsDir.'/*.json') ?: [] as $pidfile) {
            $record = Json::tryDecodeArray((string) file_get_contents($pidfile));
            if (!\is_array($record) || !isset($record['pid'])) {
                // Not a headless pidfile (e.g. another ADE's session record).
                if (!\is_array($record)) {
                    @unlink($pidfile);
                }
                continue;
            }
            $pid = (int) $record['pid'];
            if (!$this->pidAlive($pid)) {
                @unlink($pidfile);
                continue;
            }
            // A headless run has no idle signal; it counts as running.
            $grouped[(string) ($record['worktree'] ?? '')][] = new SessionInfo(self::HANDLE_PREFIX.$pid, 'running');
        }

        return $grouped;
    }

    public function owns(string $handle): bool
    {
        return str_starts_with($handle, self::HANDLE_PREFIX);
    }

    /** How often wait() checks the pid: a run that exits is seen within this. */
    public const WAIT_POLL_US = 200_000;

    /** Wait for a `pid:N` handle to exit, at most $timeoutS seconds. */
    public function wait(string $handle, int $timeoutS): void
    {
        $pid = (int) substr($handle, \strlen(self::HANDLE_PREFIX));
        $deadline = microtime(true) + $timeoutS;
        while ($this->pidAlive($pid) && microtime(true) < $deadline) {
            usleep(self::WAIT_POLL_US);
        }
    }
}
