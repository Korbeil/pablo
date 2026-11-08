<?php

declare(strict_types=1);

namespace Pablo\Doctor;

/**
 * How system:doctor / system:setup check a CLI an agent runtime or ADE
 * needs: the probe command (exit 0 = installed and logged in), the hint shown
 * on failure, the install command per OS family and where its documentation
 * lives. A soft CLI only warns in the dispatcher (agents fall back to a
 * headless run without it).
 */
final readonly class CliProbe
{
    /**
     * @param string                $cli             the CLI id doctor lists (the binary name)
     * @param list<string>          $argv            probe command
     * @param array<string, string> $installCommands PHP_OS_FAMILY => install command
     */
    public function __construct(
        public string $cli,
        public array $argv,
        public string $hint,
        public array $installCommands = [],
        public ?string $docsUrl = null,
        public bool $soft = false,
    ) {
    }
}
