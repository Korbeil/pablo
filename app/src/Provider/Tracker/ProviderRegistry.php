<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Support\PabloError;

final class ProviderRegistry
{
    public const PROVIDER_NAMES = ['github', 'jira', 'linear'];

    /** @var callable|null test seam: (string): Provider */
    private static $resolver;

    public static function setResolver(?callable $fn): void
    {
        self::$resolver = $fn;
    }

    public static function get(string $name): Provider
    {
        if (null !== self::$resolver) {
            return (self::$resolver)($name);
        }

        return match ($name) {
            'github' => new Github(),
            'jira' => new Jira(),
            'linear' => new Linear(),
            default => throw new PabloError(\sprintf('unknown provider %s (expected one of %s)', var_export($name, true), '["github", "jira", "linear"]')),
        };
    }
}
