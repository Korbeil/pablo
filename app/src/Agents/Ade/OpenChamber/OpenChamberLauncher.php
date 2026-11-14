<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\OpenChamber;

use Pablo\Agents\Ade\AbstractAgentLauncher;
use Pablo\Agents\Ade\AdeCall;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\SessionInfo;
use Pablo\Support\Files;
use Pablo\Support\Json;

/**
 * The OpenChamber ADE's launcher.
 *
 * OpenChamber is directory-based (unlike Orca's workspace model): sessions are
 * bound to a --dir path, there is no repo registration, no worktree "adoption"
 * and no display-name concept. A launch creates a session in the task worktree
 * and dispatches a prompt to it; activity is read back via `session list`.
 *
 * Launch flow (fire-and-forget, the detached watcher absorbs the async land):
 *   openchamber session create --dir <wt> --title pablo:<agent>
 *   openchamber session send --session <id> --dir <wt> --prompt <p> --agent <a>
 *
 * A failed create or send (bad model id, daemon hiccup) is logged to
 * logs/openchamber-fallback.log and falls back to the shared headless run
 * (HeadlessRunner) instead of leaving an empty zombie session.
 *
 * Status mapping (OpenChamber `sessionStatus.type`):
 *   busy/retry -> running; idle -> waiting (display) / excluded (active).
 */
final class OpenChamberLauncher extends AbstractAgentLauncher
{
    public const SESSION_TIMEOUT_S = 60;

    protected function adeName(): string
    {
        return OpenChamberAde::NAME;
    }

    /**
     * One openchamber CLI call, returning the decoded payload plus the failure
     * reason so callers can log why they fell back. Every caller has a
     * fallback path, so the short timeout only guards against a wedged daemon.
     *
     * @param list<string> $argv
     */
    private function call(array $argv): AdeCall
    {
        try {
            $out = $this->runner->run(['openchamber', ...$argv, '--json'], check: false, timeout: self::SESSION_TIMEOUT_S);
            $data = Json::tryDecodeArray($out);
        } catch (\Throwable $e) {
            return AdeCall::failed($e::class.': '.$e->getMessage());
        }
        if (!\is_array($data)) {
            $reason = 'non-JSON response: '.var_export($out, true);
            if ('' === trim($out)) {
                // What OpenChamber's desktop app answers: it ignores the
                // arguments and raises its window.
                $reason .= ' (is `openchamber` the desktop app? PABLO needs its CLI, '.OpenChamberCli::INSTALL.': see pablo system:doctor)';
            }

            return AdeCall::failed($reason);
        }
        if (($data['status'] ?? null) !== 'ok') {
            return AdeCall::failed('status:'.var_export($data['status'] ?? null, true).' error:'.var_export($data['error'] ?? null, true));
        }

        return new AdeCall($data, null);
    }

    /**
     * OpenChamber names its sessions: $sessionId is only used by the headless
     * fallback, and sessionOf() hands the OpenChamber session back for YOLO
     * mode's follow-ups. Those run headless (the inherited
     * doLaunchFollowUp()): `openchamber session send` has no way to approve
     * tool calls, so an unattended run there could stop on a question nobody
     * answers.
     */
    public function doLaunchAgent(string $worktree, string $agent, string $prompt, ?string $model = null, ?string $sessionId = null): string
    {
        // The resolved config model (project default_model / type map) wins;
        // the agent file's frontmatter model is only the fallback.
        $model ??= $this->agentModel($agent);
        $create = [
            'session', 'create', '--dir', $worktree, '--title', 'pablo:'.$agent,
        ];
        if (null !== $model) {
            $create[] = '--model';
            $create[] = $model;
        }
        $created = $this->call($create);
        // The session id PABLO assigned, for the headless fallback; from here
        // on $sessionId is OpenChamber's own.
        $assigned = $sessionId;
        $sessionId = $created->payload['sessionId'] ?? null;
        if (!\is_string($sessionId) || '' === $sessionId) {
            $this->logFallback($worktree, $agent, 'session create failed: '.($created->reason ?? 'unknown'));

            return $this->launchHeadless($worktree, $agent, $prompt, $model, $assigned);
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
        $sent = $this->call($send);
        if (null === $sent->payload) {
            // A failed send leaves the created session as an empty zombie: the
            // watcher would report it finished within seconds and the display
            // would park it as waiting forever. Drop the pidfile, log why, and
            // fall back to headless with the same model — so a bad model id
            // stays loud in the headless run's own log instead of vanishing.
            $this->logFallback($worktree, $agent, 'session send failed ('.$sessionId.'): '.($sent->reason ?? 'unknown'));
            $this->cleanupSessionFile($sessionId);

            return $this->launchHeadless($worktree, $agent, $prompt, $model, $assigned);
        }

        return $sessionId;
    }

    public function sessionOf(string $handle): ?string
    {
        return $this->headless->owns($handle) ? null : $handle;
    }

    /**
     * The model declared in the agent's frontmatter
     * (`~/.pablo/runtime/opencode/agents/<agent>.md`, the same file
     * `system:generate-agents` writes and `pablo system:install` links into
     * `~/.config/opencode`). OpenChamber otherwise falls back to its own
     * "configured selection", which can silently pick a different model than the
     * one the agent config declares.
     *
     * Only consulted when no model was resolved from config
     * (project default_model / models, per agent or per type /
     * global default_model):
     * those arrive as doLaunchAgent()'s $model parameter and win.
     *
     * @return string|null null when the file or a `model:` line is missing
     */
    private function agentModel(string $agent): ?string
    {
        $path = AgentTemplates::renderedDir().'/agents/'.$agent.'.md';
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

        return $this->headless->launchCommand($worktree, 'startup-script', $command);
    }

    private function writeSessionPidfile(string $sessionId, string $worktree, string $agent): void
    {
        $dir = $this->agentsDir();
        // best-effort: the session pidfile only helps cleanup.
        Files::tryEnsureDir($dir);
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
            $list = $this->call(['session', 'list', '--dir', $wt, '--with-status', '--limit', '200'])->payload;
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
    private function mapSessions(array $rawSessions, bool $display): array
    {
        $result = [];
        foreach ($rawSessions as $s) {
            $status = $s['status']['type'] ?? 'idle';
            $id = (string) ($s['id'] ?? '');
            if ('busy' === $status || 'retry' === $status) {
                $result[] = new SessionInfo($id, 'running');
            } elseif ($display) {
                // An idle session has finished its run; in display mode it
                // parks as waiting (review the plan, commit-and-PR).
                $result[] = new SessionInfo($id, 'waiting');
            }
        }

        return $result;
    }

    protected function sessionsFor(array $worktrees, bool $display): array
    {
        return array_map(fn (array $raw): array => $this->mapSessions($raw, $display), $this->sessionsByWorktree($worktrees));
    }

    public function setWorktreeDisplayName(string $worktree, string $name, ?string $issueNumber = null): void
    {
        // OpenChamber has no workspace display-name concept; nothing to set.
    }

    public function hasAnyAgent(string $worktree): bool
    {
        return [] !== ($this->sessionsByWorktree([$worktree])[$worktree] ?? []);
    }

    protected function waitForAdeHandle(string $handle, int $timeoutS): void
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
        $record = Json::tryDecodeArray((string) file_get_contents($path));

        return \is_array($record) && isset($record['worktree']) ? (string) $record['worktree'] : null;
    }

    private function cleanupSessionFile(string $sessionId): void
    {
        Files::tryRemove($this->agentsDir().'/openchamber-'.$sessionId.'.json');
    }
}
