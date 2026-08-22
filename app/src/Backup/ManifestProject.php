<?php

declare(strict_types=1);

namespace Pablo\Backup;

/**
 * One project's entry in a backup manifest.
 */
final readonly class ManifestProject
{
    /**
     * @param list<string> $branches
     */
    public function __construct(
        public string $name,
        public string $sourceRepoPath,
        public ?string $originUrl,
        public string $worktreesRoot,
        public string $primaryBranch,
        public array $branches,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['name'] ?? '?'),
            (string) ($data['source_repo_path'] ?? ''),
            isset($data['origin_url']) ? (string) $data['origin_url'] : null,
            (string) ($data['worktrees_root'] ?? ''),
            (string) ($data['primary_branch'] ?? 'main'),
            \is_array($data['branches'] ?? null)
                ? array_values(array_map(strval(...), $data['branches']))
                : [],
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'source_repo_path' => $this->sourceRepoPath,
            'origin_url' => $this->originUrl,
            'worktrees_root' => $this->worktreesRoot,
            'primary_branch' => $this->primaryBranch,
            'branches' => $this->branches,
        ];
    }
}
