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
        public readonly bool $dryRun = false, // nothing was changed: 'updated' means "would update"
    ) {
    }

    public function render(): string
    {
        $icon = self::ICONS[$this->status] ?? '?';
        if ($this->dryRun && 'updated' === $this->status) {
            $line = '' === $this->oldSha
                ? "🔍 would create {$this->branch} at ".substr($this->newSha, 0, 7)
                : "🔍 would update {$this->branch} ".substr($this->oldSha, 0, 7).' → '.substr($this->newSha, 0, 7);
        } else {
            $line = "{$icon} {$this->status} {$this->branch}";
            if ('updated' === $this->status) {
                $line .= ' '.substr($this->oldSha, 0, 7).' → '.substr($this->newSha, 0, 7);
            }
        }
        if ('' !== $this->fromBranch && $this->fromBranch !== $this->branch && \in_array($this->status, ['updated', 'up-to-date'], true)) {
            $line .= $this->dryRun ? " (would switch from {$this->fromBranch})" : " (switched from {$this->fromBranch})";
        }
        if ('' !== $this->detail) {
            $line .= " — {$this->detail}";
        }

        return $line;
    }
}
