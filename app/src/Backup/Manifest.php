<?php

declare(strict_types=1);

namespace Pablo\Backup;

/**
 * The manifest embedded in every backup archive: PABLO version, capture time
 * and one entry per configured project with the metadata needed to recreate
 * its worktrees on another machine.
 */
final readonly class Manifest
{
    /**
     * @param list<ManifestProject> $projects
     */
    public function __construct(
        public int $version,
        public string $pabloVersion,
        public string $createdAt,
        public string $pabloRoot,
        public array $projects,
    ) {
    }

    /**
     * @param array<string, mixed> $data decoded manifest.json
     */
    public static function fromArray(array $data): self
    {
        /** @var list<array<string, mixed>> $rawProjects */
        $rawProjects = \is_array($data['projects'] ?? null) ? $data['projects'] : [];

        return new self(
            (int) ($data['version'] ?? 0),
            (string) ($data['pablo_version'] ?? '?'),
            (string) ($data['created_at'] ?? '?'),
            (string) ($data['pablo_root'] ?? ''),
            array_map(static fn (array $p): ManifestProject => ManifestProject::fromArray($p), $rawProjects),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'pablo_version' => $this->pabloVersion,
            'created_at' => $this->createdAt,
            'pablo_root' => $this->pabloRoot,
            'projects' => array_map(static fn (ManifestProject $p): array => $p->toArray(), $this->projects),
        ];
    }
}
