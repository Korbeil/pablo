<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Forge\Gitea\GiteaForge;
use Pablo\Provider\Forge\Github\GithubForge;
use Pablo\Provider\Forge\Gitlab\GitlabForge;
use Pablo\Support\RepoSlug;

/**
 * The one place mapping a project to its forge: GitHub (gh), GitLab (glab,
 * bound to the project's GitLab host) or Gitea (gt, bound to the project's
 * Gitea host).
 */
final class ForgeRegistry implements ForgeRegistryInterface
{
    /** @var array<string, GitlabForge> host => the forge bound to it */
    private array $gitlabByHost = [];

    /** @var array<string, GiteaForge> host => the forge bound to it */
    private array $giteaByHost = [];

    public function __construct(
        private readonly GithubForge $github,
        private readonly GitlabForge $gitlab,
        private readonly GiteaForge $gitea,
        private readonly RepoSlug $repoSlug,
    ) {
    }

    public function for(ProjectConfig $cfg): ForgeInterface
    {
        $ref = $this->repoSlug->ref($cfg);

        return match ($ref->forge) {
            RepoRef::GITLAB => $this->gitlabByHost[$ref->host] ??= $this->gitlab->onHost($ref->host),
            RepoRef::GITEA => $this->giteaByHost[$ref->host] ??= $this->gitea->onHost($ref->host),
            default => $this->github,
        };
    }
}
