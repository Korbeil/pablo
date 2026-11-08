<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Agents\Runtime\RuntimeProbe;
use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Agents\Runtime\RuntimeSupport;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;

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
 */
final class ClaudeCodeRuntime implements AgentRuntimeInterface
{
    public const NAME = 'claude-code';

    private ?ClaudeCodeAgentFiles $agentFiles = null;

    /** @param string|null $dir override of ~/.pablo/runtime/claude (tests) */
    public function __construct(
        private readonly ClaudeCodeAgents $agents,
        private readonly ?string $dir = null,
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

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string
    {
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

    public function backendError(string $backend): ?string
    {
        return RuntimeSupport::openCodeOnlyBackend($backend, self::NAME);
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

    public function probe(): RuntimeProbe
    {
        return new RuntimeProbe(
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
