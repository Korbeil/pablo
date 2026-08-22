<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

/**
 * The last sync/rebase session for one project, as persisted under
 * ~/.pablo/logs. One file per project, overwritten on every run — there is
 * no history, only "what the last sync did".
 */
final readonly class RebaseLog
{
    public function __construct(
        public string $project,
        public ?string $timestamp,
        public string $strategy,
        /** @var list<SyncReport> */
        public array $reports,
    ) {
    }

    /**
     * @param array<string, mixed> $data decoded rebase-last-*.json
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $rawReports */
        $rawReports = \is_array($data['reports'] ?? null) ? $data['reports'] : [];

        return new self(
            (string) ($data['project'] ?? ''),
            \is_string($data['timestamp'] ?? null) ? $data['timestamp'] : null,
            (string) ($data['strategy'] ?? '?'),
            array_map(static fn (array $r): SyncReport => SyncReport::fromArray($r), $rawReports),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'timestamp' => $this->timestamp,
            'project' => $this->project,
            'strategy' => $this->strategy,
            'reports' => array_map(
                static fn (SyncReport $r): array => [
                    'branch' => $r->branch,
                    'action' => $r->action,
                    'behind' => $r->behind,
                    'ahead' => $r->ahead,
                    'conflict_files' => $r->conflictFiles,
                    'detail' => $r->detail,
                    'agent_handle' => $r->agentHandle,
                ],
                $this->reports,
            ),
        ];
    }
}
