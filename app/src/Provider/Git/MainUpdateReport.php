<?php

declare(strict_types=1);

namespace Pablo\Provider\Git;

/**
 * Outcome of updating a project's primary checkout (repo_path) to its
 * remote primary branch.
 */
final class MainUpdateReport
{
    private const ICONS = [
        'updated' => '✅',
        'up-to-date' => '=',
        'dirty' => '✋',
        'ahead' => '✋',
        'busy' => '✋',
        'checked-out-elsewhere' => '⚠️',
        'missing' => '⚠️',
    ];

    public function __construct(
        public readonly string $repo,
        public readonly string $branch,
        public readonly string $status, // updated | up-to-date | dirty | ahead | busy | checked-out-elsewhere | missing
        public readonly string $fromBranch = '',
        public readonly string $oldSha = '',
        public readonly string $newSha = '',
        public readonly string $detail = '',
    ) {
    }

    public function render(): string
    {
        $icon = self::ICONS[$this->status] ?? '?';
        $line = "{$icon} {$this->status} {$this->branch}";
        if ('updated' === $this->status) {
            $line .= ' '.substr($this->oldSha, 0, 7).' → '.substr($this->newSha, 0, 7);
        }
        if ('' !== $this->fromBranch && $this->fromBranch !== $this->branch && \in_array($this->status, ['updated', 'up-to-date'], true)) {
            $line .= " (switched from {$this->fromBranch})";
        }
        if ('' !== $this->detail) {
            $line .= " — {$this->detail}";
        }

        return $line;
    }
}
