<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Claude Code takes a PABLO-assigned session id (--session-id): a bare UUID,
 * recorded as-is.
 */
final class ClaudeCodeSessions implements RuntimeSessionsInterface
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function handle(string $sessionId): string
    {
        return $sessionId;
    }

    public function owns(string $handle): bool
    {
        return 1 === preg_match(self::UUID, $handle);
    }

    public function resumeCommand(string $handle): string
    {
        return 'claude --resume '.$handle;
    }

    public function findSession(ProcessRunnerInterface $runner, string $worktree): ?string
    {
        return null;
    }
}
