<?php

declare(strict_types=1);

namespace Pablo\Doctor;

/**
 * How system:doctor / system:setup check a CLI (gh, a tracker's, an agent
 * runtime's or an ADE's): the probe command (exit 0 = installed and logged
 * in), the hint shown on failure, the install command per OS family and where
 * its documentation lives. A soft CLI only warns in the dispatcher (agents
 * fall back to a headless run, a tracker only fails its own projects).
 */
final readonly class CliProbe
{
    /**
     * @param string                           $cli             the CLI id doctor lists (the binary name)
     * @param list<string>                     $argv            probe command
     * @param array<string, string>            $installCommands PHP_OS_FAMILY => install command
     * @param (\Closure(string): ?string)|null $refuse          given the binary's path, why it is the wrong
     *                                                          program (never run then), or null
     */
    public function __construct(
        public string $cli,
        public array $argv,
        public string $hint,
        public array $installCommands = [],
        public ?string $docsUrl = null,
        public bool $soft = false,
        public ?\Closure $refuse = null,
    ) {
    }
}
