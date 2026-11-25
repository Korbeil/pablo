<?php

declare(strict_types=1);

namespace Pablo\Provider\Forge;

use Pablo\Config\ProjectConfig;

interface ForgeRegistryInterface
{
    /**
     * The forge a project's pull requests live on (repo.forge, else told by
     * its origin remote: Support\RepoSlug::ref()). Throws a PabloError when
     * the repository cannot be resolved, like RepoSlug::for().
     */
    public function for(ProjectConfig $cfg): ForgeInterface;
}
