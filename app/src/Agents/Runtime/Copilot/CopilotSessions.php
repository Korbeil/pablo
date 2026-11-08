<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Copilot takes a PABLO-assigned session id (--session-id), a UUID like
 * Claude Code's: its handles carry a `copilot:` prefix so they cannot be
 * mistaken for a Claude Code one.
 */
final class CopilotSessions implements RuntimeSessionsInterface
{
    public const PREFIX = 'copilot:';

    public function handle(string $sessionId): string
    {
        return self::PREFIX.$sessionId;
    }

    public function owns(string $handle): bool
    {
        return str_starts_with($handle, self::PREFIX);
    }

    /** A bare id (from this runtime) is taken as a Copilot session too. */
    public function resumeCommand(string $handle): string
    {
        return 'copilot --resume='.($this->owns($handle) ? substr($handle, \strlen(self::PREFIX)) : $handle);
    }

    public function findSession(ProcessRunnerInterface $runner, string $worktree): ?string
    {
        return null;
    }
}
