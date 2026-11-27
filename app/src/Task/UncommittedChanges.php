<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Support\PabloError;

/**
 * TaskCloser refused to close: the worktree holds uncommitted files nobody
 * approved losing, or they changed since they were approved. $files is the
 * current list, for the caller to show and ask again.
 */
final class UncommittedChanges extends PabloError
{
    /**
     * @param list<string> $files `git status --porcelain` entries ("XY path")
     */
    public function __construct(string $message, public readonly array $files)
    {
        parent::__construct($message);
    }
}
