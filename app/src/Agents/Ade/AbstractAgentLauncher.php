<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade;

use Pablo\Agents\Ade\Headless\HeadlessRunner;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Domain\DisplayCache;
use Pablo\Domain\Time;
use Pablo\Store\Store;
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
    public const WAIT_TIMEOUT_MS = 3_600_000;

    private readonly Store $store;

    private readonly string $shimPath;

    protected readonly AgentRuntimeInterface $runtime;

    protected readonly HeadlessRunner $headless;

    /**
     * Mirrors Store::defaultRoot(): resolved per process, never at container
     * compile time (the compiled container is cached under app/var/cache).
     */
    private static function defaultShimPath(): string
    {
        $override = getenv('PABLO_SHIM');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return PabloPaths::home().'/.local/bin/pablo';
    }

    /**
     * The process runner is required: a default real one would let a test that
     * forgets it reach real CLIs. Side-effect-free collaborators (Time) keep
     * a default; the ADE always passes its own injected ones through.
     *
     * @param string      $ade      the `agent_backend` the detached subprocesses re-resolve
     * @param string|null $shimPath absolute path used for detached self-reinvocation
     */
    public function __construct(
        private readonly string $ade,
        protected readonly ProcessRunnerInterface $runner,
        protected readonly Time $time = new Time(),
        ?Store $store = null,
        ?string $shimPath = null,
        ?AgentRuntimeInterface $runtime = null,
    ) {
        $this->runtime = $runtime ?? (new AgentRuntimeRegistry())->resolve(AgentRuntimeRegistry::DEFAULT);
        $this->store = $store ?? new Store();
        $this->shimPath = $shimPath ?? self::defaultShimPath();
        $this->headless = new HeadlessRunner($this->time, $this->runtime);
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
        if (!is_dir($logs)) {
            @mkdir($logs, 0o777, true);
        }
        file_put_contents(
            $logs.'/'.$this->ade.'-fallback.log',
            $this->time->utcnow()." worktree={$worktree} label={$label} reason={$reason}\n",
            \FILE_APPEND,
        );
    }

    public function launch(string $worktree, \Pablo\Domain\Agent $agent, string $prompt, string $project, string $branch): string
    {
        $pid = Detached::spawn([
            $this->shimPath, 'internal:launch-agent',
            '--backend', $this->ade,
            '--worktree', $worktree,
            '--agent', $agent->value,
            '--prompt', $prompt,
            '--project', $project,
            '--branch', $branch,
        ]);

        return HeadlessRunner::HANDLE_PREFIX.$pid;
    }

    public function runStartupScript(string $worktree, string $script, string $project, string $branch): string
    {
        $pid = Detached::spawn([
            $this->shimPath, 'internal:run-startup-script',
            '--backend', $this->ade,
            '--worktree', $worktree,
            '--script', $script,
            '--project', $project,
            '--branch', $branch,
        ]);

        return HeadlessRunner::HANDLE_PREFIX.$pid;
    }

    public function spawnWatcher(string $project, string $branch, string $handle, string $agent, string $then, ?string $expectState = null, ?string $runId = null, ?string $promptFingerprint = null): void
    {
        $argv = [
            $this->shimPath, 'internal:watch-agent',
            '--backend', $this->ade,
            '--project', $project, '--branch', $branch,
            '--handle', $handle, '--agent', $agent,
            '--then', $then,
        ];
        if (null !== $expectState) {
            $argv[] = '--expect-state';
            $argv[] = $expectState;
        }
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

            $lock = $this->store->taskLock($project, $branch, timeoutS: 2);
            try {
                $task = $this->store->get($project, $branch);
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
                $this->store->save($task);
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
            $timeoutS = self::WAIT_TIMEOUT_MS / 1000;
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
