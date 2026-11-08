<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * GitHub Copilot CLI: `copilot -p` with PABLO's agents installed as
 * symlinked `pablo-<agent>.agent.md` files under ~/.copilot/agents (so the
 * user can also pick them in their own sessions).
 *
 * Every launch repeats the agent's policy as CLI flags (the exact built-in
 * tool set, which also hides the user's MCP tools; pre-approved kinds; shell
 * denies), turns off the built-in MCP servers and the ask-user tool (nobody
 * answers a headless run), and gets a PABLO-assigned session id so analytics
 * and `copilot --resume` find it.
 */
final class CopilotRuntime implements AgentRuntimeInterface
{
    /** Claude Code model aliases, which Copilot does not know. */
    private const CLAUDE_ALIASES = ['sonnet', 'opus', 'haiku'];

    public function __construct(private readonly CopilotAgents $agents)
    {
    }

    /**
     * The user's Copilot directory (COPILOT_HOME, else ~/.copilot): agents,
     * skills and session state. Resolved per call.
     */
    public static function userConfigDir(): string
    {
        $env = getenv('COPILOT_HOME');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return (getenv('HOME') ?: '~').'/.copilot';
    }

    public function name(): string
    {
        return self::COPILOT;
    }

    public function binary(): string
    {
        return 'copilot';
    }

    public function defaultModel(): string
    {
        return 'claude-sonnet-5.5';
    }

    public function newSessionId(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string
    {
        $argv = ['copilot', '--agent', CopilotAgents::agentId($agent)];
        if (null !== $model) {
            $argv[] = '--model';
            $argv[] = $model;
        }
        if (null !== $sessionId) {
            $argv[] = '--session-id';
            $argv[] = $sessionId;
        }
        $argv = [
            ...$argv,
            '--no-ask-user', '--no-auto-update', '--no-color', '--disable-builtin-mcps',
            ...$this->agents->profile($agent)->cliArgs(),
            // The prompt goes last, as the value of -p.
            '-p', $prompt,
        ];

        return implode(' ', array_map(OpenCodeRuntime::word(...), $argv));
    }

    public function interactiveCommand(string $agent, string $prompt, ?string $model, ?string $sessionId, string $doneMarker): ?string
    {
        return null; // its one-shot run already streams to the terminal
    }

    public function sessionRef(string $handle): string
    {
        // A bare UUID from this runtime is a Copilot session, not Claude Code's.
        return SessionRef::command(SessionRef::isClaudeSession($handle) ? SessionRef::handle(self::COPILOT, $handle) : $handle);
    }

    public function installDir(): string
    {
        return self::userConfigDir().'/agents';
    }

    public function modelError(string $model): ?string
    {
        if (str_contains($model, '/')) {
            return \sprintf(
                "'%s' looks like an OpenCode provider/model id; agent_runtime copilot takes a Copilot model id such as claude-sonnet-5.5 or gpt-5.3-codex (bring-your-own-model ids are not supported)",
                $model,
            );
        }
        if (\in_array($model, self::CLAUDE_ALIASES, true) || 1 === preg_match('/^claude-[a-z]+-\d+-\d+$/', $model)) {
            return \sprintf(
                "'%s' is a Claude Code model name; agent_runtime copilot spells model ids with dots, e.g. claude-sonnet-5.5",
                $model,
            );
        }

        return null;
    }
}
