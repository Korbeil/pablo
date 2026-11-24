<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\OpenChamber;

use Pablo\Agents\Ade\AbstractAgentLauncher;
use Pablo\Agents\Ade\AdeCall;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\SessionInfo;
use Pablo\Domain\Time;
use Pablo\Support\Files;
use Pablo\Support\Json;
use Pablo\Support\ProcessRunnerInterface;

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
 * (HeadlessRunner) instead of leaving an empty zombie session — unless the
 * session shows the prompt landed anyway (the CLI times out on a slow daemon
 * that still takes it), in which case it is kept, so the agent runs once.
 * A create refused because the model catalog of a directory OpenChamber has
 * never seen is still loading (every new worktree) is retried first.
 *
 * Status mapping (OpenChamber `sessionStatus.type`):
 *   busy/retry -> running; idle -> waiting (display) / excluded (active).
 */
final class OpenChamberLauncher extends AbstractAgentLauncher
{
    public const SESSION_TIMEOUT_S = 60;

    /** Waits before each check that a failed `session send` landed anyway. */
    private const DELIVERY_CHECK_DELAYS_US = [2_000_000, 5_000_000, 10_000_000];

    /**
     * Waits before each new `session create` while OpenChamber answers that
     * the session selection is not ready yet: the first model-catalog read
     * for a directory it has never seen (every new task worktree) comes back
     * empty while OpenCode still loads it, and a second read has it.
     */
    private const CREATE_RETRY_DELAYS_US = [1_000_000, 2_000_000, 4_000_000];

    /** OpenChamber's 503 for a model catalog still loading for --dir. */
    private const NOT_READY = 'Session selection is not ready';

    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    /**
     * @param (\Closure(int): void)|null $sleep test seam: wait that many microseconds
     */
    public function __construct(
        ProcessRunnerInterface $runner,
        Time $time,
        AgentRuntimeInterface $runtime,
        ?\Closure $sleep = null,
    ) {
        parent::__construct($runner, $time, $runtime);
        $this->sleep = $sleep ?? static function (int $us): void { usleep($us); };
    }

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
        [$created, $retries] = $this->createSession($create);
        // The session id PABLO assigned, for the headless fallback; from here
        // on $sessionId is OpenChamber's own.
        $assigned = $sessionId;
        $sessionId = $created->payload['sessionId'] ?? null;
        if (!\is_string($sessionId) || '' === $sessionId) {
            $retried = $retries > 0 ? ' after '.$retries.' retries' : '';
            $this->logFallback($worktree, $agent, 'session create failed'.$retried.': '.($created->reason ?? 'unknown'));

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
            // The CLI gives up on a slow daemon after its own 4 s while the
            // daemon still takes the prompt: falling back then would run the
            // agent twice. Keep the session when the prompt did land.
            if ($this->promptDelivered($sessionId, $worktree)) {
                $this->logFallback($worktree, $agent, 'session send reported a failure but the prompt was delivered ('.$sessionId.'), no fallback: '.($sent->reason ?? 'unknown'));

                return $sessionId;
            }
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

    /**
     * `session create`, tried again after each CREATE_RETRY_DELAYS_US wait
     * while OpenChamber answers that the session selection is not ready (a
     * failed create makes no session, so a retry never makes two). Any other
     * failure is returned at once.
     *
     * @param list<string> $argv
     *
     * @return array{AdeCall, int} the last call and how many retries it took
     */
    private function createSession(array $argv): array
    {
        $created = $this->call($argv);
        $retries = 0;
        foreach (self::CREATE_RETRY_DELAYS_US as $delayUs) {
            if (null !== $created->payload || !str_contains($created->reason ?? '', self::NOT_READY)) {
                break;
            }
            ($this->sleep)($delayUs);
            ++$retries;
            $created = $this->call($argv);
        }

        return [$created, $retries];
    }

    /**
     * Whether a `session send` that reported a failure reached the daemon
     * anyway: the session is busy, or holds a user message. Asked once per
     * DELIVERY_CHECK_DELAYS_US entry, after that wait, so a daemon still
     * catching up gets the time to; an unanswered check counts as not
     * delivered, so a wedged daemon still gets its headless run.
     */
    private function promptDelivered(string $sessionId, string $worktree): bool
    {
        foreach (self::DELIVERY_CHECK_DELAYS_US as $delayUs) {
            ($this->sleep)($delayUs);
            $payload = $this->call(['session', 'messages', '--session', $sessionId, '--dir', $worktree])->payload;
            if (null === $payload) {
                continue;
            }
            if (\in_array($payload['sessionStatus']['type'] ?? null, ['busy', 'retry'], true)) {
                return true;
            }
            foreach ($payload['messages'] ?? [] as $message) {
                if (\is_array($message) && 'user' === ($message['role'] ?? null)) {
                    return true;
                }
            }
        }

        return false;
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
