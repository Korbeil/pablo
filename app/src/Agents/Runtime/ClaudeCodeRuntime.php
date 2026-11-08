<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Support\PabloPaths;

/**
 * Claude Code: `claude -p` (headless) or an interactive `claude` session (in
 * an Orca terminal, so the user watches the conversation) with PABLO's agents passed at launch through
 * `--agents ~/.pablo/runtime/claude/agents.json`. They are also linked into
 * ~/.claude/agents as pablo-<agent> (see ClaudeCodeAgents::renderAgentFiles)
 * so the user's own sessions list them; those files carry tool-level limits
 * only, the shell-pattern rules below exist solely as launch flags.
 *
 * Every launch repeats the agent's permissions as CLI flags — the only place
 * shell patterns can be expressed — runs with the user's own settings and
 * hooks (Orca, RTK) but without their MCP servers (--strict-mcp-config), and
 * gets a PABLO-assigned session id so analytics and `claude --resume` find it.
 */
final class ClaudeCodeRuntime implements AgentRuntimeInterface
{
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

        return (getenv('HOME') ?: '~').'/.claude';
    }

    public function name(): string
    {
        return self::CLAUDE_CODE;
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
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function agentsFile(): string
    {
        return $this->installDir().'/agents.json';
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string
    {
        return $this->command(['claude', '-p'], $this->agentsFile(), $agent, $prompt, $model, $sessionId);
    }

    /**
     * The same session without -p: Claude's own interface, which stays open
     * for the user once the agent has answered. A Stop hook (merged with the
     * user's own hooks by --settings) creates $doneMarker when the agent
     * finishes answering, the run's end for PABLO's watcher.
     *
     * Interactive `claude` only takes --agents as inline JSON (a file path
     * needs -p), so the agent's own definition is read from agents.json and
     * passed inline; null (one-shot instead) when it cannot be.
     */
    public function interactiveCommand(string $agent, string $prompt, ?string $model, ?string $sessionId, string $doneMarker): ?string
    {
        $raw = @file_get_contents($this->agentsFile());
        $agents = false !== $raw ? json_decode($raw, true) : null;
        if (!\is_array($agents) || !\is_array($agents[$agent] ?? null)) {
            return null;
        }
        $inline = json_encode([$agent => $agents[$agent]], \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR);
        $settings = ['hooks' => ['Stop' => [['hooks' => [[
            'type' => 'command',
            'command' => 'touch '.escapeshellarg($doneMarker),
        ]]]]]];

        return $this->command(['claude', '--settings', json_encode($settings, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES)], $inline, $agent, $prompt, $model, $sessionId);
    }

    /**
     * @param list<string> $head
     * @param string       $agents agents.json's path (-p), or the agent inline
     */
    private function command(array $head, string $agents, string $agent, string $prompt, ?string $model, ?string $sessionId): string
    {
        $argv = [...$head, '--agents', $agents, '--agent', $agent];
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

        return implode(' ', array_map(OpenCodeRuntime::word(...), $argv));
    }

    public function sessionRef(string $handle): string
    {
        return SessionRef::command($handle);
    }

    public function installDir(): string
    {
        return $this->dir ?? self::defaultDir();
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
}
