<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloPaths;

/**
 * The project's repository: repo.*, plus the top-level worktrees_root and
 * startup_script, which are about its checkouts.
 */
final class RepoConfig
{
    public function __construct(
        public readonly string $path,
        public readonly string $primaryBranch,
        public readonly string $worktreesRoot,
        public readonly string $primaryRemote = 'origin',
        public readonly ?string $startupScript = null,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function fromYaml(array $project, string $file): self
    {
        $path = PabloPaths::expandTilde((string) ConfigValues::required($project, 'repo.path', $file));
        $primaryBranch = (string) ConfigValues::required($project, 'repo.primary_branch', $file);
        $primaryRemote = (string) (ConfigValues::optional($project, 'repo.primary_remote') ?? 'origin');

        $rawRoot = $project['worktrees_root'] ?? null;
        if (null !== $rawRoot && '' !== $rawRoot) {
            $worktreesRoot = PabloPaths::expandTilde((string) $rawRoot);
        } else {
            $worktreesRoot = PabloPaths::pabloPath('worktrees').'/'.basename($path);
        }
        $startup = $project['startup_script'] ?? null;

        return new self(
            path: $path,
            primaryBranch: $primaryBranch,
            worktreesRoot: $worktreesRoot,
            primaryRemote: $primaryRemote,
            startupScript: $startup ? PabloPaths::expandTilde((string) $startup) : null,
        );
    }
}
