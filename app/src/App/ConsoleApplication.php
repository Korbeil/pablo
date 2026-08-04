<?php

declare(strict_types=1);

namespace Pablo\App;

use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Standalone pablo console application (no web kernel). Commands are
 * injected from the DI container (tagged `console.command`).
 */
final class ConsoleApplication extends SymfonyApplication
{
    public const VERSION = '0.1.0';

    /**
     * @param iterable<\Symfony\Component\Console\Command\Command> $commands
     */
    public function __construct(iterable $commands = [])
    {
        parent::__construct('pablo', self::VERSION);
        foreach ($commands as $command) {
            $this->addCommand($command);
        }
    }
}
