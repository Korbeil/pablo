<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * The agent CLI that actually runs PABLO's agents: OpenCode (`opencode run`)
 * or Claude Code (`claude -p`). Orthogonal to the agent *backend* (Orca,
 * OpenChamber, headless), which only decides where that command runs.
 *
 * Selected globally (`agent_runtime` in ~/.pablo/config.yaml, overridable by
 * PABLO_AGENT_RUNTIME) and resolved per call by AgentRuntimeResolver — never
 * at container compile time.
 */
interface AgentRuntimeInterface
{
    public const OPENCODE = 'opencode';
    public const CLAUDE_CODE = 'claude-code';

    public const NAMES = [self::OPENCODE, self::CLAUDE_CODE];

    /** 'opencode' | 'claude-code' */
    public function name(): string;

    /** The CLI binary the runtime needs on PATH. */
    public function binary(): string;

    /** Model documented as the default for this runtime. */
    public function defaultModel(): string;

    /**
     * A session id PABLO assigns up front (Claude Code accepts --session-id),
     * or null when the runtime picks its own (OpenCode).
     */
    public function newSessionId(): ?string;

    /**
     * Shell command (no `cd`, no redirection) running $agent once,
     * non-interactively, on $prompt. Every argument is shell-escaped.
     */
    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string;

    /** How the user resumes the session behind $handle, e.g. `opencode -s <id>`. */
    public function sessionRef(string $handle): string;

    /**
     * Where the generated agent definitions are read from by the runtime:
     * ~/.config/opencode/agents (symlinks) or ~/.pablo/claude.
     */
    public function installDir(): string;

    /** Why $model cannot be used with this runtime, or null when it can. */
    public function modelError(string $model): ?string;
}
