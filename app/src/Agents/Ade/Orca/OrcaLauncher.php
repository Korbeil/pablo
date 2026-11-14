<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Orca;

use Pablo\Agents\Ade\AbstractAgentLauncher;
use Pablo\Agents\Ade\AdeCall;
use Pablo\Agents\SessionInfo;
use Pablo\Support\Files;
use Pablo\Support\Json;
use Pablo\Support\PabloError;

/**
 * The Orca ADE's launcher: launching agents on worktrees and querying their
 * activity.
 *
 * Primary path is the Orca CLI, running the selected runtime's one-shot
 * command (AgentRuntimeInterface::launchCommand()) in an Orca terminal. When Orca
 * refuses (e.g. selector_not_found for an unregistered repo) or is
 * unreachable, PABLO falls back to running that same command headless
 * (HeadlessRunner), tracked via pidfiles under ~/.pablo/agents/.
 */
final class OrcaLauncher extends AbstractAgentLauncher
{
    public const ORCA_CALL_TIMEOUT_S = 60;
    public const ORCA_ADOPT_WAIT_S = 15;
    public const RUNNING_STATES = ['working', 'running'];
    public const FINISHED_STATES = ['done', 'completed'];

    protected function adeName(): string
    {
        return OrcaAde::NAME;
    }

    /**
     * @param list<string> $argv
     */
    private function orca(array $argv): AdeCall
    {
        $out = '';
        try {
            $out = $this->runner->run([OrcaCli::command(), ...$argv, '--json'], check: false, timeout: self::ORCA_CALL_TIMEOUT_S);
            $data = Json::tryDecodeArray($out);
        } catch (\Throwable $e) {
            return AdeCall::failed($e::class.': '.$e->getMessage().'; raw_stdout='.var_export($out, true));
        }
        if (!\is_array($data) || ($data['ok'] ?? false) !== true) {
            return AdeCall::failed('ok:false response: '.var_export($data, true));
        }

        return new AdeCall(\is_array($data['result'] ?? null) ? $data['result'] : [], null);
    }

    /**
     * Blocking per-worktree flock serialising `orca terminal create` spans.
     */
    private function withLaunchLock(string $worktree, callable $fn): mixed
    {
        $locks = $this->agentsDir().'/locks';
        Files::ensureDir($locks);
        $stem = preg_replace('/[^a-zA-Z0-9]/', '_', $worktree) ?: 'root';
        $fd = fopen($locks.'/'.$stem.'.launch.lock', 'w');
        if (false === $fd) {
            throw new PabloError('cannot open launch lock: '.$locks.'/'.$stem.'.launch.lock');
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
     *
     * @return string|null null once adopted, else why not (with Orca's last reply)
     */
    private function waitForOrcaAdoption(string $worktree, int $maxWaitS = self::ORCA_ADOPT_WAIT_S): ?string
    {
        $deadline = microtime(true) + $maxWaitS;
        $last = null;
        while (microtime(true) < $deadline) {
            $call = $this->orca(['worktree', 'show', '--worktree', 'path:'.$worktree]);
            if (null !== $call->payload) {
                return null;
            }
            $last = $call->reason;
            usleep(1_000_000);
        }

        return 'orca adoption timeout for '.$worktree.' (last reply: '.($last ?? 'none').')';
    }

    /**
     * Open a `pablo:<label>` Orca terminal running $command in $worktree
     * (once Orca has adopted it), serialised per worktree by the launch lock.
     * When Orca refuses or is unreachable, the reason is logged and the caller
     * falls back to a headless run.
     *
     * @return string|null the terminal handle, null to fall back
     */
    private function createTerminal(string $worktree, string $label, string $command): ?string
    {
        $call = $this->withLaunchLock($worktree, function () use ($worktree, $label, $command): AdeCall {
            if (null !== ($notAdopted = $this->waitForOrcaAdoption($worktree))) {
                return AdeCall::failed($notAdopted);
            }

            return $this->orca([
                'terminal', 'create',
                '--worktree', 'path:'.$worktree,
                '--title', 'pablo:'.$label,
                '--command', $command,
            ]);
        });
        $result = $call->payload;
        $handle = null === $result ? null
            : $result['handle'] ?? $result['agentTerminalHandle'] ?? $result['startupTerminal']['handle'] ?? $result['terminal']['handle'] ?? null;
        if (null === $handle) {
            $this->logFallback($worktree, $label, $call->reason);

            return null;
        }

        return (string) $handle;
    }

    public function doLaunchAgent(string $worktree, string $agent, string $prompt, ?string $model = null, ?string $sessionId = null): string
    {
        // The agent runs inside the worktree, in an Orca terminal: in the
        // runtime's interactive interface when it has one (the OpenCode TUI),
        // else its one-shot command. The same session id carries over to the
        // headless fallback so `claude --resume` / `copilot --resume` and
        // analytics find the run either way. An unattended run (a session id
        // given: YOLO mode) is always the one-shot command: the interactive
        // interface would hold the terminal — and the watcher — until the
        // user quits it.
        $interactive = null === $sessionId ? $this->runtime->interactiveCommand($agent, $prompt, $model) : null;
        $sessionId ??= $this->runtime->newSessionId();
        $command = 'cd '.escapeshellarg($worktree).'; '
            .($interactive ?? $this->runtime->launchCommand($agent, $prompt, $model, $sessionId));

        return $this->createTerminal($worktree, $agent, $command)
            ?? $this->launchHeadless($worktree, $agent, $prompt, $model, $sessionId);
    }

    /** In an Orca terminal too (`pablo:yolo-…`), so the follow-up shows next to the plan. */
    public function doLaunchFollowUp(string $worktree, string $label, string $sessionId, string $prompt, ?string $model = null): string
    {
        $command = 'cd '.escapeshellarg($worktree).'; '.$this->runtime->followUpCommand($sessionId, $prompt, $model);

        return $this->createTerminal($worktree, $label, $command)
            ?? $this->headless->launchFollowUp($worktree, $label, $sessionId, $prompt, $model);
    }

    public function doRunStartupScript(string $worktree, string $script): string
    {
        $command = 'bash '.escapeshellarg($script).'; exec bash';

        return $this->createTerminal($worktree, 'startup-script', $command)
            ?? $this->headless->launchCommand($worktree, 'startup-script', $command);
    }

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void
    {
        $this->orca([
            'worktree', 'set',
            '--worktree', 'path:'.$worktree,
            '--display-name', $name,
            '--issue', $issueNumber ?? 'null',
        ]);
    }

    /**
     * @param list<array<string, mixed>> $orcaWorktrees
     *
     * @return array<string, list<SessionInfo>>
     */
    private function sessionsByWorktreeFromOrca(array $orcaWorktrees, bool $display): array
    {
        $grouped = [];
        foreach ($orcaWorktrees as $wt) {
            $path = (string) ($wt['path'] ?? '');
            foreach ($wt['agents'] ?? [] as $agent) {
                $state = $agent['state'] ?? null;
                if (\in_array($state, self::FINISHED_STATES, true)) {
                    if (!$display) {
                        continue; // a finished agent (e.g. "done") must not count as an active session
                    }
                    // A finished analyst is parked awaiting the user, so the
                    // display reports it as waiting.
                    $status = 'waiting';
                } else {
                    $status = \in_array($state, self::RUNNING_STATES, true) ? 'running' : 'waiting';
                }
                $grouped[$path][] = new SessionInfo((string) ($agent['paneKey'] ?? ''), $status);
            }
        }

        return $grouped;
    }

    /** One `orca worktree ps` for every queried worktree, plus their headless runs. */
    protected function sessionsFor(array $worktrees, bool $display): array
    {
        $result = $this->orca(['worktree', 'ps', '--limit', '200'])->payload;
        $orcaGrouped = null !== $result ? $this->sessionsByWorktreeFromOrca($result['worktrees'] ?? [], $display) : [];
        $headlessGrouped = $this->headless->sessionsByWorktree();
        $out = [];
        foreach ($worktrees as $wt) {
            $out[$wt] = array_merge($orcaGrouped[$wt] ?? [], $headlessGrouped[$wt] ?? []);
        }

        return $out;
    }

    public function hasAnyAgent(string $worktree): bool
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

    protected function waitForAdeHandle(string $handle, int $timeoutS): void
    {
        try {
            $this->runner->run([
                OrcaCli::command(), 'terminal', 'wait', '--terminal', $handle,
                '--for', 'exit', '--timeout-ms', (string) ($timeoutS * 1000), '--json',
            ], check: false, timeout: $timeoutS + 120);
        } catch (\Throwable) {
            // orca gone/hung: treat the run as finished rather than wedging
        }
    }
}
