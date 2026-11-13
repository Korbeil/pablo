<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * ci.*: the checks that never fail a task.
 */
final class CiConfig
{
    /**
     * @param list<string> $ignoreChecks
     */
    public function __construct(
        public readonly array $ignoreChecks = [],
    ) {
    }

    /**
     * @param array<string, mixed> $project
     * @param array<string, mixed> $defaults
     */
    public static function fromYaml(array $project, array $defaults): self
    {
        return new self(array_values((array) ConfigValues::merged($project, $defaults, 'ci', 'ignore_checks')));
    }
}
