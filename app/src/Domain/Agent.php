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
    /**
     * YOLO mode's two follow-up runs, in the session of the state agent that
     * wrote the plan: the runtime's own build agent executing it, then the
     * same session running /pablo-commit-and-pr. Not PABLO agents of their
     * own: labels for the launch records, analytics and `models`.
     */
    case YoloImplement = 'yolo-implement';
    case YoloCommit = 'yolo-commit';

    /** Whether it runs on a model (the startup script runs a shell script). */
    public function runsModel(): bool
    {
        return self::StartupScript !== $this;
    }

    /** Whether it is one of YOLO mode's follow-up runs. */
    public function isYoloStep(): bool
    {
        return self::YoloImplement === $this || self::YoloCommit === $this;
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
