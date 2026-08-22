<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

interface ProviderRegistryInterface
{
    /**
     * The provider for a configured issue_tracker.provider name.
     */
    public function get(string $name): Provider;
}
