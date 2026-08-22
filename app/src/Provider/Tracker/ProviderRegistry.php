<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Support\PabloError;

final class ProviderRegistry implements ProviderRegistryInterface
{
    public const PROVIDER_NAMES = ['github', 'jira', 'linear'];

    public function __construct(
        private readonly Github $github,
        private readonly Jira $jira,
        private readonly Linear $linear,
    ) {
    }

    public function get(string $name): Provider
    {
        return match ($name) {
            'github' => $this->github,
            'jira' => $this->jira,
            'linear' => $this->linear,
            default => throw new PabloError(\sprintf('unknown provider %s (expected one of %s)', var_export($name, true), '["github", "jira", "linear"]')),
        };
    }
}
