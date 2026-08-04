<?php

declare(strict_types=1);

namespace Pablo\Support;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Tracker\Github;

/**
 * Shared repo-slug helper used by states/poller/listing (Python's
 * providers.github.repo_slug).
 */
final class RepoSlug
{
    /** @var callable|null */
    private static $for;

    public static function setFor(?callable $fn): void
    {
        self::$for = $fn;
    }

    public static function for(ProjectConfig $cfg): string
    {
        if (null !== self::$for) {
            return (self::$for)($cfg);
        }

        return Github::slug($cfg);
    }
}
