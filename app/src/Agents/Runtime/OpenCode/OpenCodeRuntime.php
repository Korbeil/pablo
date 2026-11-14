<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Agents\Runtime\RuntimeSupport;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Support\ProcessRunnerInterface;

/**
 * OpenCode: `opencode run --agent <a> '<prompt>'`, agents installed as
 * symlinked .md files under ~/.config/opencode/agents. The default runtime.
 */
final class OpenCodeRuntime implements AgentRuntimeInterface
{
    public const NAME = 'opencode';

    /** OpenCode's session id prefix; `-s` creates a session under any such id. */
    private const SESSION_PREFIX = 'ses_pablo';

    /** How long the TUI waits for the background run to create its session. */
    private const SESSION_WAIT_S = 60;

    /** OpenCode's built-in write agent, which YOLO mode's follow-ups run as. */
    public const BUILD_AGENT = 'build';

    private ?OpenCodeAgentFiles $agentFiles = null;

    public function __construct(private readonly AgentTemplates $templates)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'OpenCode';
    }

    public function binary(): string
    {
        return 'opencode';
    }

    public function defaultModel(): string
    {
        return 'opencode-go/glm-5.3-flash';
    }

    public function newSessionId(): string
    {
        return self::SESSION_PREFIX.bin2hex(random_bytes(10));
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string
    {
        return $this->runArgs($agent, $prompt, $model, $sessionId);
    }

    /**
     * The OpenCode TUI on the agent's session. The TUI takes no --agent or
     * --model, so `opencode run` starts the session in the background, with a
     * session id PABLO picks (`-s` creates it), and the TUI opens on it as
     * soon as it exists: it follows the run live, then the user carries on
     * with the session's agent and model. Run through `sh -c`: Orca types the
     * command into the user's own shell, which may not be POSIX. When the run
     * fails before creating the session, its error stays on screen.
     */
    public function interactiveCommand(string $agent, string $prompt, ?string $model): string
    {
        $id = $this->newSessionId();
        $session = escapeshellarg($id);
        $run = $this->runArgs($agent, $prompt, $model, $id);
        $script = 'err=$(mktemp); nohup '.$run.' >/dev/null 2>"$err" & pid=$!; i=0; '
            .'until opencode session export '.$session.' >/dev/null 2>&1; do '
            .'if ! kill -0 "$pid" 2>/dev/null || [ "$i" -ge '.self::SESSION_WAIT_S.' ]; then cat "$err"; rm -f "$err"; exit 1; fi; '
            .'sleep 1; i=$((i+1)); done; rm -f "$err"; exec opencode -s '.$session;

        return 'sh -c '.escapeshellarg($script);
    }

    /**
     * `opencode run -s <session> --agent build --auto`: OpenCode's own build
     * agent continues the analyst's session, `--auto` approving every
     * permission not explicitly denied. `opencode run` takes no per-run
     * permission rules, so FollowUpPolicy reaches it only through the prompt
     * (and the user's own OpenCode `permission` denies, which `--auto`
     * keeps).
     */
    public function followUpCommand(string $sessionId, string $prompt, ?string $model): string
    {
        return 'opencode run -s '.escapeshellarg($sessionId)
            .(null !== $model ? ' --model '.escapeshellarg($model) : '')
            .' --agent '.self::BUILD_AGENT.' --auto '.escapeshellarg($prompt);
    }

    /**
     * `opencode run` on $prompt, under session $sessionId when given (`-s`
     * creates it). OpenCode v2 removed `run --dir` and the TUI's positional
     * directory + --prompt flags: callers cd into the worktree first.
     */
    private function runArgs(string $agent, string $prompt, ?string $model, ?string $sessionId): string
    {
        return 'opencode run'
            .(null !== $sessionId ? ' -s '.escapeshellarg($sessionId) : '')
            .(null !== $model ? ' --model '.escapeshellarg($model) : '')
            .' --agent '.RuntimeSupport::word($agent).' '.escapeshellarg($prompt);
    }

    public function modelError(string $model): ?string
    {
        return null;
    }

    public function sessions(): RuntimeSessionsInterface
    {
        return new OpenCodeSessions();
    }

    public function agentFiles(): RuntimeAgentFilesInterface
    {
        return $this->agentFiles ??= new OpenCodeAgentFiles($this->templates);
    }

    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface
    {
        return new OpenCodeUsage($runner);
    }

    public function oneShot(): RuntimeOneShotInterface
    {
        return new OpenCodeOneShot();
    }

    public function probe(): CliProbe
    {
        return new CliProbe(
            cli: 'opencode',
            argv: ['opencode', '--version'],
            hint: 'install opencode: https://opencode.ai',
            installCommands: [
                'Darwin' => 'curl -fsSL https://opencode.ai/install | bash',
                'Linux' => 'curl -fsSL https://opencode.ai/install | bash',
            ],
            docsUrl: 'https://opencode.ai',
        );
    }
}
