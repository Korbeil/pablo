<?php

declare(strict_types=1);

namespace Pablo\App;

use Symfony\Component\Console\Application as SymfonyApplication;

/**
 * Standalone pablo console application. Commands are injected from the DI
 * container, tagged `pablo.command` (see config/services.php).
 */
final class ConsoleApplication extends SymfonyApplication
{
    /** Shown in a repository checkout, where Box never replaced the placeholder. */
    public const CHECKOUT_VERSION = 'dev';

    /**
     * Replaced by Box when the binary is built (a `replacements` entry set by
     * castor build:binary and .github/workflows/binary.yml): the first 7
     * characters of the commit SHA, which is also the release name. Left as
     * is in a checkout.
     * A property, not a constant, so the check below is not constant-folded.
     */
    private static string $buildVersion = '@package_version@';

    /**
     * @param iterable<\Symfony\Component\Console\Command\Command> $commands
     */
    public function __construct(iterable $commands = [])
    {
        parent::__construct('pablo', self::version());
        foreach ($commands as $command) {
            $this->addCommand($command);
        }
    }

    /**
     * The binary's version, or `dev` in a checkout. Compared by its first
     * character, not against the placeholder itself: Box would replace that
     * literal too.
     */
    public static function version(): string
    {
        return str_starts_with(self::$buildVersion, '@') ? self::CHECKOUT_VERSION : self::$buildVersion;
    }
}
