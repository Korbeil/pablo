<?php

declare(strict_types=1);

namespace Pablo\Support;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Git\GitRepoInterface;

/**
 * Shared repo-slug helper used by states/poller/listing: "owner/repo" for
 * the configured upstream (issue_tracker.repo) when set, else parsed from
 * the origin remote. Needs git (origin URL), hence a service.
 */
final class RepoSlug
{
    public function __construct(private readonly GitRepoInterface $git)
    {
    }

    public function for(ProjectConfig $cfg): string
    {
        if (null !== $cfg->issueRepo) {
            return $cfg->issueRepo;
        }
        $url = $this->git->originUrl($cfg->repoPath);
        if (null === $url) {
            throw new PabloError("{$cfg->name}: repo at {$cfg->repoPath} has no origin remote");
        }
        if (1 !== preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?(?:/|$)#', $url, $m)) {
            throw new PabloError("{$cfg->name}: origin remote is not a GitHub URL: {$url}");
        }

        return "{$m[1]}/{$m[2]}";
    }
}
