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

    /** Whether it runs on a model (the startup script runs a shell script). */
    public function runsModel(): bool
    {
        return self::StartupScript !== $this;
    }

    /**
     * The labels of the agents that run on a model: the agent keys a
     * models entry may map.
     *
     * @return list<string>
     */
    public static function modelAgents(): array
    {
        return array_values(array_map(
            static fn (self $agent): string => $agent->value,
            array_filter(self::cases(), static fn (self $agent): bool => $agent->runsModel()),
        ));
    }
}
