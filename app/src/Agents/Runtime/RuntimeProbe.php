<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

/**
 * How system:doctor / system:setup check a runtime's CLI: the probe command
 * (exit 0 = installed and logged in), the hint shown on failure, the install
 * command per OS family and where its documentation lives.
 */
final readonly class RuntimeProbe
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
        public array $installCommands,
        public string $docsUrl,
    ) {
    }
}
