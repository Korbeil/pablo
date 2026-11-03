<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * OpenCode: `opencode run --agent <a> '<prompt>'`, agents installed as
 * symlinked .md files under ~/.config/opencode/agents.
 */
final class OpenCodeRuntime implements AgentRuntimeInterface
{
    public function name(): string
    {
        return self::OPENCODE;
    }

    public function binary(): string
    {
        return 'opencode';
    }

    public function defaultModel(): string
    {
        return 'opencode-go/glm-5.3-flash';
    }

    public function newSessionId(): ?string
    {
        return null;
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string
    {
        // OpenCode v2 removed `run --dir` and the TUI's positional directory
        // + --prompt flags: callers cd into the worktree first.
        return 'opencode run'
            .(null !== $model ? ' --model '.escapeshellarg($model) : '')
            .' --agent '.self::word($agent).' '.escapeshellarg($prompt);
    }

    /** Agent names are plain words; quote anything else. */
    public static function word(string $value): string
    {
        return 1 === preg_match('/^[\w.-]+$/', $value) ? $value : escapeshellarg($value);
    }

    public function sessionRef(string $handle): string
    {
        return SessionRef::command($handle);
    }

    public function installDir(): string
    {
        return (getenv('HOME') ?: '~').'/.config/opencode/agents';
    }

    public function modelError(string $model): ?string
    {
        return null;
    }
}
