<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Forge\Github\GithubForge;
use Pablo\Provider\Forge\Gitlab\GitlabForge;
use Pablo\Support\RepoSlug;

/**
 * The one place mapping a project to its forge: GitHub (gh) or GitLab
 * (glab, bound to the project's GitLab host).
 */
final class ForgeRegistry implements ForgeRegistryInterface
{
    /** @var array<string, GitlabForge> host => the forge bound to it */
    private array $gitlabByHost = [];

    public function __construct(
        private readonly GithubForge $github,
        private readonly GitlabForge $gitlab,
        private readonly RepoSlug $repoSlug,
    ) {
    }

    public function for(ProjectConfig $cfg): ForgeInterface
    {
        $ref = $this->repoSlug->ref($cfg);
        if (!$ref->isGitlab()) {
            return $this->github;
        }

        return $this->gitlabByHost[$ref->host] ??= $this->gitlab->onHost($ref->host);
    }
}
