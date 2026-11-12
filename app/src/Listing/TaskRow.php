<?php

declare(strict_types=1);

namespace Pablo\Listing;

use Pablo\Domain\AgentActivity;
use Pablo\Domain\PrBadge;
use Pablo\Domain\PrBadgeKind;
use Pablo\Domain\State;
use Pablo\Domain\Task;

/**
 * One task of `pablo task:list`, resolved once (display cache or live fetch)
 * and rendered either as a table row or as JSON, so the two cannot drift.
 */
final readonly class TaskRow
{
    public function __construct(
        public Task $task,
        public string $stateLabel,
        public string $stateEmoji,
        /** Tracker status cell: the status, 'N/A' without an issue, '?' when unknown. */
        public string $tracker,
        /** PR cell, as rendered by PrBadge (or read back from the display cache). */
        public string $prCell,
        public AgentCell $agentCell,
        public bool $waitingForFeedback,
        public ?string $repoSlug,
    ) {
    }

    public function stateCell(): string
    {
        return '' === $this->stateEmoji ? $this->stateLabel : "{$this->stateEmoji} {$this->stateLabel}";
    }

    public function pr(): PrBadge
    {
        return PrBadge::fromDisplay($this->prCell);
    }

    public function agents(): AgentActivity
    {
        return AgentActivity::fromDisplay((int) $this->agentCell->count, $this->agentCell->activity);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $task = $this->task;
        $issue = $task->issue;
        $pr = $this->pr();
        $agents = $this->agents();

        return [
            'project' => $task->project,
            'branch' => $task->branch,
            'state' => $task->state->value,
            'state_label' => $this->stateLabel,
            'waiting_for_feedback' => $this->waitingForFeedback,
            'summary' => $task->summary,
            'issue' => null === $issue ? null : [
                'provider' => $issue->provider,
                'key' => $issue->key,
                'title' => $issue->title,
                'url' => $issue->url,
            ],
            'tracker_status' => null === $issue || '?' === $this->tracker ? null : $this->tracker,
            'pr' => $pr->exists() ? [
                'number' => $pr->number,
                'url' => $pr->url($this->repoSlug),
                'state' => $pr->kind->value,
                'draft' => PrBadgeKind::Draft === $pr->kind,
            ] : null,
            'ci' => [
                'red' => State::CiRed === $task->state,
                'ignored' => $task->ciIgnored,
            ],
            'agents' => [
                'total' => $agents->total,
                'running' => $agents->running,
                'waiting' => $agents->waiting,
            ],
            'worktree_path' => $task->worktreePath,
            'state_entered_at' => $task->stateEnteredAt,
            'created_at' => $task->createdAt,
            'updated_at' => $task->updatedAt,
            'cached_at' => $task->displayCache->at,
        ];
    }
}
