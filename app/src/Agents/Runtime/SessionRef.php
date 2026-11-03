<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * How to resume a recorded agent session, decided from the id itself so a
 * log written under one runtime still reads right after switching: Claude
 * Code session ids are UUIDs (PABLO assigns them), OpenCode's are `ses_…`.
 */
final class SessionRef
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public static function isClaudeSession(string $handle): bool
    {
        return 1 === preg_match(self::UUID, $handle);
    }

    public static function command(string $handle): string
    {
        return self::isClaudeSession($handle) ? 'claude --resume '.$handle : 'opencode -s '.$handle;
    }
}
