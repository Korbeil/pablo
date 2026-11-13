<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * A runtime's agent sessions: the handle PABLO records for one, and how the
 * user resumes it. Handles must be recognisable on their own (see
 * SessionRef), so a log written under one runtime still reads right after
 * switching to another.
 */
interface RuntimeSessionsInterface
{
    /** The handle PABLO records for $sessionId. */
    public function handle(string $sessionId): string;

    /** Whether $handle is one of this runtime's. */
    public function owns(string $handle): bool;

    /** How the user resumes the session behind $handle, e.g. `opencode -s <id>`. */
    public function resumeCommand(string $handle): string;
}
