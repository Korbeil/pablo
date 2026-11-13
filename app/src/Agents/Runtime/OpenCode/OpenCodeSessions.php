<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Agents\Runtime\RuntimeSessionsInterface;

/**
 * OpenCode sessions (`ses_…`), recorded as-is. PABLO assigns its own
 * (`ses_pablo…`, see OpenCodeRuntime::newSessionId()); the handles logs
 * recorded before that carry OpenCode's own ids and still resolve.
 */
final class OpenCodeSessions implements RuntimeSessionsInterface
{
    private const PREFIX = 'ses_';

    public function handle(string $sessionId): string
    {
        return $sessionId;
    }

    public function owns(string $handle): bool
    {
        return str_starts_with($handle, self::PREFIX);
    }

    public function resumeCommand(string $handle): string
    {
        return 'opencode -s '.$handle;
    }
}
