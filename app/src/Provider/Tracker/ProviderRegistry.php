<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Support\PabloError;

final class ProviderRegistry implements ProviderRegistryInterface
{
    public const PROVIDER_NAMES = ['github', 'jira', 'linear', 'gitlab', 'gitea'];

    public function __construct(
        private readonly Github $github,
        private readonly Gitlab $gitlab,
        private readonly Gitea $gitea,
        private readonly Jira $jira,
        private readonly Linear $linear,
    ) {
    }

    public function get(string $name): Provider
    {
        return match ($name) {
            'github' => $this->github,
            'gitlab' => $this->gitlab,
            'gitea' => $this->gitea,
            'jira' => $this->jira,
            'linear' => $this->linear,
            default => throw new PabloError(\sprintf('unknown provider %s (expected one of %s)', var_export($name, true), json_encode(self::PROVIDER_NAMES))),
        };
    }
}
