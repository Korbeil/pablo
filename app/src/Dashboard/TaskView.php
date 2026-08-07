<?php

declare(strict_types=1);

namespace Pablo\Dashboard;

use Pablo\Domain\AgentActivity;
use Pablo\Domain\Issue;
use Pablo\Domain\PrBadge;
use Pablo\Domain\State;
use Pablo\Listing\Listing;

/**
 * One row of the dashboard's task tables.
 *
 * Built entirely from a Task plus the poller's display cache — no provider
 * lookups — so a page render never waits on gh/orca/a tracker CLI.
 */
final readonly class TaskView
{
    public function __construct(
        public string $project,
        public string $branch,
        public State $state,
        public string $stateEmoji,
        public string $stateLabel,
        public AgentActivity $agents,
        public PrBadge $pr,
        public ?string $prUrl,
        public ?Issue $issue,
        public ?string $summary,
        public ?string $trackerStatus,
        public string $stateEnteredAt,
        public string $since,
        public string $worktreePath,
        public bool $needsAttention,
        public ?string $polledAt,
    ) {
    }

    /** Issue key + title, else the prompt summary, else a dash. */
    public function label(): string
    {
        if (null !== $this->issue) {
            return "{$this->issue->key} {$this->issue->title}";
        }

        return $this->summary ?? '-';
    }

    /** Ordering within a table, mirroring the terminal listing exactly. */
    public function rank(): int
    {
        return Listing::stateRank($this->state);
    }

    /** Bulma colour modifier for the state tag. */
    public function stateColor(): string
    {
        return match ($this->state) {
            State::TestingFailed => 'is-danger',
            State::CiRed => 'is-danger',
            State::RequestChanges => 'is-warning',
            State::NeedsTesting => 'is-link',
            State::WaitingReview, State::ReadyToReview => 'is-info',
            State::Draft => 'pablo-chip-muted',
            State::InProgress => 'is-primary',
            State::Waiting => 'pablo-chip',
        };
    }

    /** Lucide icon name for the state. */
    public function stateIcon(): string
    {
        return match ($this->state) {
            State::TestingFailed => 'lucide:triangle-alert',
            State::CiRed => 'lucide:circle-x',
            State::RequestChanges => 'lucide:rotate-ccw',
            State::NeedsTesting => 'lucide:flask-conical',
            State::WaitingReview, State::ReadyToReview => 'lucide:eye',
            State::Draft => 'lucide:file-pen',
            State::InProgress => 'lucide:hammer',
            State::Waiting => 'lucide:coffee',
        };
    }
}
