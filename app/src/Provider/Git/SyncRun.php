<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

/**
 * One past sync run, as kept in the per-project sync history: the session
 * (same shape as rebase-last-<project>.json) plus whether it applied
 * changes or was a dry run.
 */
final readonly class SyncRun
{
    public function __construct(
        public RebaseLog $log,
        public bool $apply,
    ) {
    }

    /**
     * @param array<string, mixed> $data one decoded history line
     */
    public static function fromArray(array $data): self
    {
        return new self(RebaseLog::fromArray($data), (bool) ($data['apply'] ?? false));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['apply' => $this->apply, ...$this->log->toArray()];
    }

    /**
     * This run restricted to the reports whose action is in $actions (all of
     * them when $actions is empty).
     *
     * @param list<string> $actions
     */
    public function only(array $actions): self
    {
        if ([] === $actions) {
            return $this;
        }

        return new self(
            new RebaseLog(
                $this->log->project,
                $this->log->timestamp,
                $this->log->strategy,
                array_values(array_filter($this->log->reports, static fn (SyncReport $r): bool => \in_array($r->action, $actions, true))),
            ),
            $this->apply,
        );
    }
}
