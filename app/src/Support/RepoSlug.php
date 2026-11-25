<?php

declare(strict_types=1);

namespace Pablo\Support;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Forge\RepoRef;
use Pablo\Provider\Git\GitRepoInterface;

/**
 * Shared repository helper used by states/poller/listing: which forge
 * (GitHub or GitLab) and host a project's pull requests live on, and the
 * repository's slug there — "owner/repo" or "group/subgroup/project".
 *
 * The slug is the configured upstream (issue_tracker.repo) when set, else
 * the origin remote's path. The forge is repo.forge when set, else told by
 * the origin's host (RepoRef::forgeOfHost()); a project with an upstream and
 * no origin stays on GitHub, as before GitLab support. Needs git (origin
 * URL), hence a service.
 */
final class RepoSlug
{
    public function __construct(private readonly GitRepoInterface $git)
    {
    }

    public function for(ProjectConfig $cfg): string
    {
        return $this->ref($cfg)->slug;
    }

    public function ref(ProjectConfig $cfg): RepoRef
    {
        $configured = $cfg->tracker->repo;
        $url = $this->git->originUrl($cfg->repo->path);
        $remote = null === $url ? null : RepoRef::parseRemote($url);
        if (null === $remote) {
            if (null === $configured) {
                throw new PabloError(null === $url ? "{$cfg->name}: repo at {$cfg->repo->path} has no origin remote" : "{$cfg->name}: cannot read the origin remote URL: {$url}");
            }
            $forge = $cfg->repo->forge ?? RepoRef::GITHUB;

            return new RepoRef($forge, RepoRef::DEFAULT_HOSTS[$forge], $configured);
        }
        [$host, $path] = $remote;
        $forge = $cfg->repo->forge ?? RepoRef::forgeOfHost($host);
        if (null !== $configured && (null === $forge || (RepoRef::GITHUB === $forge && RepoRef::GITHUB !== RepoRef::forgeOfHost($host)))) {
            // An upstream on GitHub, with an origin elsewhere (a mirror):
            // the upstream decides, as it did before GitLab support.
            return new RepoRef(RepoRef::GITHUB, RepoRef::DEFAULT_HOSTS[RepoRef::GITHUB], $configured);
        }
        if (null === $forge) {
            throw new PabloError("{$cfg->name}: origin remote is neither a GitHub nor a GitLab URL: {$url} (a self-hosted GitLab: set repo.forge: gitlab)");
        }
        if (RepoRef::GITHUB === $forge && RepoRef::GITHUB !== RepoRef::forgeOfHost($host)) {
            throw new PabloError("{$cfg->name}: origin remote is not a GitHub URL: {$url} (only github.com is supported)");
        }
        $slug = $configured ?? $path;
        if (RepoRef::GITHUB === $forge && null === $configured) {
            // owner/repo: whatever follows the second segment is not the slug
            $slug = implode('/', \array_slice(explode('/', $path), 0, 2));
        }

        return new RepoRef($forge, $host, $slug);
    }
}
