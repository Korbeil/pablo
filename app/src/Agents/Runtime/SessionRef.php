<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * How to resume a recorded agent session, decided from the handle itself so
 * a log written under one runtime still reads right after switching:
 * Claude Code session ids are bare UUIDs (PABLO assigns them), Copilot's are
 * UUIDs too, so their handles carry a `copilot:` prefix (see handle()), and
 * OpenCode's are `ses_…`.
 */
final class SessionRef
{
    public const COPILOT_PREFIX = 'copilot:';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * The handle PABLO records for $sessionId of $runtime: the id itself,
     * prefixed for Copilot so it cannot be mistaken for a Claude Code one.
     */
    public static function handle(string $runtime, string $sessionId): string
    {
        return AgentRuntimeInterface::COPILOT === $runtime ? self::COPILOT_PREFIX.$sessionId : $sessionId;
    }

    public static function isClaudeSession(string $handle): bool
    {
        return 1 === preg_match(self::UUID, $handle);
    }

    public static function isCopilotSession(string $handle): bool
    {
        return str_starts_with($handle, self::COPILOT_PREFIX);
    }

    public static function command(string $handle): string
    {
        if (self::isCopilotSession($handle)) {
            return 'copilot --resume='.substr($handle, \strlen(self::COPILOT_PREFIX));
        }

        return self::isClaudeSession($handle) ? 'claude --resume '.$handle : 'opencode -s '.$handle;
    }
}
