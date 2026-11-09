<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Agents\Runtime\RuntimeSupport;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\CliProbe;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Claude Code: `claude -p` with PABLO's agents passed at launch through
 * `--agents ~/.pablo/runtime/claude/agents.json`. They are also linked into
 * ~/.claude/agents as pablo-<agent> (see ClaudeCodeAgentFiles) so the user's
 * own sessions list them; those files carry tool-level limits only, the
 * shell-pattern rules below exist solely as launch flags.
 *
 * Every launch repeats the agent's permissions as CLI flags — the only place
 * shell patterns can be expressed — runs with the user's own settings and
 * hooks (Orca, RTK) but without their MCP servers (--strict-mcp-config), and
 * gets a PABLO-assigned session id so analytics and `claude --resume` find it.
 *
 * Detached runs (no terminal) are Remote Control sessions by default, so they
 * show up live in claude.ai/code and the Claude apps (remoteControl()); runs
 * in a terminal the user watches stay `claude -p`.
 */
final class ClaudeCodeRuntime implements AgentRuntimeInterface
{
    public const NAME = 'claude-code';

    public const REMOTE_CONTROL_ENV = 'PABLO_CLAUDE_REMOTE_CONTROL';

    /**
     * Stop/StopFailure hook ending a Remote Control session once the agent
     * has answered (or failed): from the hook's shell it walks up to the
     * process whose command line carries the session id read from the hook's
     * input — the `claude` itself — and terminates it.
     */
    public const STOP_HOOK = 's=$(sed -n \'s/.*"session_id": *"\\([^"]*\\)".*/\\1/p\' | head -n 1); p=$PPID; '
        .'while [ -n "$s" ] && [ "${p:-1}" -gt 1 ]; do '
        .'if ps -o args= -p "$p" | grep -qF -- "$s"; then kill -TERM "$p"; exit 0; fi; '
        .'p=$(ps -o ppid= -p "$p" | tr -d " "); done';

    private ?ClaudeCodeAgentFiles $agentFiles = null;

    /** @param string|null $dir override of ~/.pablo/runtime/claude (tests) */
    public function __construct(
        private readonly ClaudeCodeAgents $agents,
        private readonly ?string $dir = null,
        private readonly GlobalConfig $globalConfig = new GlobalConfig(),
    ) {
    }

    /**
     * ~/.pablo/runtime/claude (agents.json, agents/, commands/), or
     * PABLO_CLAUDE_DIR; resolved per call, never at container compile time.
     */
    public static function defaultDir(): string
    {
        $override = getenv('PABLO_CLAUDE_DIR');
        if (false !== $override && '' !== $override) {
            return $override;
        }

        return PabloPaths::runtimeDir().'/claude';
    }

    /**
     * The user's Claude Code directory (CLAUDE_CONFIG_DIR, else ~/.claude):
     * transcripts, and where `pablo system:install` links the agents, the
     * command and the skill.
     */
    public static function userConfigDir(): string
    {
        $env = getenv('CLAUDE_CONFIG_DIR');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return PabloPaths::home().'/.claude';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Claude Code';
    }

    public function binary(): string
    {
        return 'claude';
    }

    public function defaultModel(): string
    {
        return 'sonnet';
    }

    public function newSessionId(): string
    {
        return RuntimeSupport::uuid();
    }

    public function agentsFile(): string
    {
        return ($this->dir ?? self::defaultDir()).'/agents.json';
    }

    /**
     * Whether detached runs are Remote Control sessions: PABLO_CLAUDE_REMOTE_CONTROL,
     * else `claude_code.remote_control` in ~/.pablo/config.yaml, else on.
     * Read per call, never at container compile time.
     */
    public function remoteControl(): bool
    {
        $env = getenv(self::REMOTE_CONTROL_ENV);
        $value = false !== $env && '' !== $env ? $env : ($this->globalConfig->defaults()['claude_code']['remote_control'] ?? true);

        return \is_bool($value) ? $value : (filter_var($value, \FILTER_VALIDATE_BOOL, \FILTER_NULL_ON_FAILURE) ?? true);
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string
    {
        if (null !== $detachedIn && null !== $sessionId && $this->remoteControl()) {
            $command = $this->remoteControlCommand($agent, $prompt, $model, $sessionId, $detachedIn);
            if (null !== $command) {
                return $command;
            }
        }

        $argv = ['claude', '-p', '--agents', $this->agentsFile(), '--agent', $agent];
        if (null !== $model) {
            $argv[] = '--model';
            $argv[] = $model;
        }
        if (null !== $sessionId) {
            $argv[] = '--session-id';
            $argv[] = $sessionId;
        }
        $argv[] = '--strict-mcp-config';
        $argv = [...$argv, ...$this->agents->profile($agent)->cliArgs()];
        // `--`: the variadic tool flags would otherwise swallow the prompt.
        $argv[] = '--';
        $argv[] = $prompt;

        return RuntimeSupport::commandLine($argv);
    }

    /**
     * The same run as an interactive `claude --remote-control` session, so
     * claude.ai/code, the Claude apps and Claude Desktop show it live. Null
     * (then `claude -p` runs) when there is no agents file or no `script`.
     *
     * Interactive `claude` needs a terminal: `script` gives it one. It never
     * exits on its own: the Stop hook ends it once the agent has answered, so
     * the command still ends with the run. Interactive `--agents` only takes
     * inline JSON, every tool outside the agent's allow list is refused
     * rather than asked (dontAsk: nobody answers a prompt), and the worktree
     * is trusted up front so Claude Code does not stop on its trust dialog.
     */
    private function remoteControlCommand(string $agent, string $prompt, ?string $model, string $sessionId, string $worktree): ?string
    {
        $raw = @file_get_contents($this->agentsFile());
        $agents = false !== $raw ? json_decode($raw, true) : null;
        $definition = \is_array($agents) ? ($agents[$agent] ?? null) : null;
        if (!\is_array($definition) || null === (new ExecutableFinder())->find('script')) {
            return null;
        }
        ClaudeCodeTrust::trust($worktree);

        $stop = [['hooks' => [['type' => 'command', 'command' => self::STOP_HOOK]]]];
        $json = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;
        $argv = [
            'claude', '--remote-control', 'pablo '.$agent.' '.basename(rtrim($worktree, '/')),
            '--agents', json_encode([$agent => $definition], $json), '--agent', $agent,
        ];
        if (null !== $model) {
            $argv[] = '--model';
            $argv[] = $model;
        }
        $argv = [
            ...$argv,
            '--session-id', $sessionId,
            '--strict-mcp-config',
            '--settings', json_encode(['hooks' => ['Stop' => $stop, 'StopFailure' => $stop]], $json),
            ...$this->agents->profile($agent)->cliArgs('dontAsk'),
            '--', $prompt,
        ];
        $claude = RuntimeSupport::commandLine($argv);

        // BSD script (macOS) takes the command as arguments, util-linux's as
        // one -c string (-e: its exit status, -f: flush the log as it goes).
        return 'Darwin' === \PHP_OS_FAMILY
            ? 'script -q /dev/null '.$claude
            : 'script -qefc '.escapeshellarg('exec '.$claude).' /dev/null';
    }

    public function modelError(string $model): ?string
    {
        if (str_contains($model, '/')) {
            return \sprintf(
                "'%s' looks like an OpenCode provider/model id; agent_runtime claude-code takes a Claude model alias (sonnet, opus, haiku) or a full model id such as claude-sonnet-5-5",
                $model,
            );
        }

        return null;
    }

    public function sessions(): RuntimeSessionsInterface
    {
        return new ClaudeCodeSessions();
    }

    public function agentFiles(): RuntimeAgentFilesInterface
    {
        return $this->agentFiles ??= new ClaudeCodeAgentFiles($this->agents, $this->dir);
    }

    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface
    {
        return new ClaudeCodeUsage();
    }

    public function oneShot(): RuntimeOneShotInterface
    {
        return new ClaudeCodeOneShot();
    }

    public function probe(): CliProbe
    {
        return new CliProbe(
            cli: 'claude',
            argv: ['claude', 'auth', 'status', '--text'],
            hint: 'run: claude auth login',
            installCommands: [
                'Darwin' => 'curl -fsSL https://claude.ai/install.sh | bash',
                'Linux' => 'curl -fsSL https://claude.ai/install.sh | bash',
            ],
            docsUrl: 'https://code.claude.com/docs',
        );
    }
}
