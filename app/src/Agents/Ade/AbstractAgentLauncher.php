<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

use Pablo\Agents\Ade\Headless\HeadlessRunner;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\SessionInfo;
use Pablo\Domain\Agent;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Shared plumbing for every ADE's launcher.
 *
 * An ADE implements the launching/activity-mapping half of
 * AgentLauncherInterface; this base owns everything ADE-agnostic: the
 * detached self-reinvocation machinery, the spawned watcher, the
 * display-cache refresh, and a HeadlessRunner — the headless runs every ADE
 * falls back to (and Sync launches the conflict resolver with).
 *
 * launch()/runStartupScript() each spawn a detached `pablo
 * internal:launch-agent` / `internal:run-startup-script` subprocess and
 * return immediately (an ADE CLI can hang outside an interactive session).
 * The actual ADE work happens in doLaunchAgent()/doRunStartupScript(), which
 * only ever run inside that detached subprocess.
 */
abstract class AbstractAgentLauncher implements AgentLauncherInterface
{
    public const WAIT_TIMEOUT_S = 3_600;

    protected readonly HeadlessRunner $headless;

    /**
     * The process runner is required: a default real one would let a test that
     * forgets it reach real CLIs. The ADE passes its own injected ones through.
     */
    public function __construct(
        protected readonly ProcessRunnerInterface $runner,
        protected readonly Time $time,
        protected readonly AgentRuntimeInterface $runtime,
    ) {
        $this->headless = new HeadlessRunner($this->time, $this->runtime);
    }

    /** The `agent_backend` the detached subprocesses re-resolve. */
    abstract protected function adeName(): string;

    /**
     * Sessions of each queried worktree (a worktree with none may be missing).
     * $display counts a finished/idle agent as waiting — parked awaiting the
     * user (review the plan, /pablo-commit-and-pr) — so the "💭 Waiting for
     * feedback" split surfaces it; without it only live agents count, so a
     * finished one never blocks closure or relaunch.
     *
     * @param list<string> $worktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    abstract protected function sessionsFor(array $worktrees, bool $display): array;

    /** @return array<int, SessionInfo> */
    public function activeSessions(string $worktree): array
    {
        return $this->sessionsFor([$worktree], false)[$worktree] ?? [];
    }

    /** @return array<int, SessionInfo> */
    public function displaySessions(string $worktree): array
    {
        return $this->sessionsFor([$worktree], true)[$worktree] ?? [];
    }

    /**
     * @param list<string> $worktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    public function bulkDisplaySessions(array $worktrees): array
    {
        $grouped = $this->sessionsFor($worktrees, true);
        $out = [];
        foreach ($worktrees as $worktree) {
            $out[$worktree] = $grouped[$worktree] ?? [];
        }

        return $out;
    }

    /**
     * Absolute path used for detached self-reinvocation: PABLO_SHIM, else
     * ~/.local/bin/pablo. Resolved per call, never at container compile time
     * (the compiled container is cached under app/var/cache).
     */
    private static function shimPath(): string
    {
        $override = getenv('PABLO_SHIM');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return PabloPaths::home().'/.local/bin/pablo';
    }

    public function agentsDir(): string
    {
        return $this->headless->agentsDir();
    }

    public function runtime(): AgentRuntimeInterface
    {
        return $this->runtime;
    }

    /**
     * Append one launch fallback to logs/<ade>-fallback.log, so a run that
     * ended up headless leaves a trace of why.
     */
    protected function logFallback(string $worktree, string $label, ?string $reason): void
    {
        $logs = $this->agentsDir().'/logs';
        // best-effort: a fallback log must never stop the launch.
        Files::tryEnsureDir($logs);
        file_put_contents(
            $logs.'/'.$this->adeName().'-fallback.log',
            $this->time->utcnow()." worktree={$worktree} label={$label} reason={$reason}\n",
            \FILE_APPEND,
        );
    }

    public function launch(string $worktree, Agent $agent, string $prompt, string $project, string $branch): string
    {
        $pid = Detached::spawn([
            self::shimPath(), 'internal:launch-agent',
            '--backend', $this->adeName(),
            '--worktree', $worktree,
            '--agent', $agent->value,
            '--prompt', $prompt,
            '--project', $project,
            '--branch', $branch,
        ]);

        return HeadlessRunner::HANDLE_PREFIX.$pid;
    }

    public function launchFollowUp(string $worktree, Agent $step, string $sessionId, string $prompt, string $project, string $branch): string
    {
        $pid = Detached::spawn([
            self::shimPath(), 'internal:launch-agent',
            '--backend', $this->adeName(),
            '--worktree', $worktree,
            '--agent', $step->value,
            '--prompt', $prompt,
            '--project', $project,
            '--branch', $branch,
            '--follow-up-session', $sessionId,
        ]);

        return HeadlessRunner::HANDLE_PREFIX.$pid;
    }

    /** Headless by default; an ADE with somewhere to show it overrides this. */
    public function doLaunchFollowUp(string $worktree, string $label, string $sessionId, string $prompt, ?string $model = null): string
    {
        return $this->headless->launchFollowUp($worktree, $label, $sessionId, $prompt, $model);
    }

    public function sessionOf(string $handle): ?string
    {
        return null;
    }

    public function newSessionId(): string
    {
        return $this->runtime->newSessionId();
    }

    public function runStartupScript(string $worktree, string $script, string $project, string $branch): string
    {
        $pid = Detached::spawn([
            self::shimPath(), 'internal:run-startup-script',
            '--backend', $this->adeName(),
            '--worktree', $worktree,
            '--script', $script,
            '--project', $project,
            '--branch', $branch,
        ]);

        return HeadlessRunner::HANDLE_PREFIX.$pid;
    }

    public function spawnWatcher(string $project, string $branch, string $handle, string $agent, ?string $runId = null, ?string $promptFingerprint = null): void
    {
        $argv = [
            self::shimPath(), 'internal:watch-agent',
            '--backend', $this->adeName(),
            '--project', $project, '--branch', $branch,
            '--handle', $handle, '--agent', $agent,
        ];
        if (null !== $runId) {
            $argv[] = '--run-id';
            $argv[] = $runId;
        }
        if (null !== $promptFingerprint) {
            $argv[] = '--prompt-fingerprint';
            $argv[] = $promptFingerprint;
        }
        Detached::spawn($argv);
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

            // Resolved per call (Store reads PABLO_STATE_DIR at runtime).
            $store = new Store(time: $this->time);
            $lock = $store->taskLock($project, $branch, timeoutS: 2);
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
                    at: $this->time->utcnow(),
                );
                $store->save($task);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // best-effort
        }
    }

    public function launchHeadless(string $worktree, string $agent, string $prompt, ?string $model = null, ?string $sessionId = null): string
    {
        return $this->headless->launch($worktree, $agent, $prompt, $model, $sessionId);
    }

    public function waitForHandle(string $handle, int $timeoutS = 0): void
    {
        if (0 === $timeoutS) {
            $timeoutS = self::WAIT_TIMEOUT_S;
        }
        if ($this->headless->owns($handle)) {
            $this->headless->wait($handle, $timeoutS);

            return;
        }
        $this->waitForAdeHandle($handle, $timeoutS);
    }

    /**
     * Wait for an ADE-specific handle (e.g. an Orca terminal or an
     * OpenChamber session id) to finish. Never called for headless handles.
     */
    abstract protected function waitForAdeHandle(string $handle, int $timeoutS): void;
}
