<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Config\ProjectConfig;
use Pablo\Provider\Tracker\Provider;

/**
 * The project, its provider, and the provider-specific issue reference an
 * issue URL or key resolved to.
 */
final readonly class IssueMatch
{
    public function __construct(
        public ProjectConfig $cfg,
        public Provider $provider,
        public string $ref,
    ) {
    }
}
