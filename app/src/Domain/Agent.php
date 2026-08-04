<?php

declare(strict_types=1);

namespace Pablo\Domain;

/**
 * The agent labels PABLO launches (the stored agent_launches map keys).
 */
enum Agent: string
{
    case TaskAnalyst = 'task-analyst';
    case StartupScript = 'startup-script';
    case CiAnalyst = 'ci-analyst';
    case PrFeedback = 'pr-feedback';
    case TaskFeedback = 'task-feedback';
    case RebaseConflictResolver = 'rebase-conflict-resolver';

    public static function tryByName(string $name): ?self
    {
        return self::tryFrom($name);
    }
}
