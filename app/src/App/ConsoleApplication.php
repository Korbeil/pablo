<?php

declare(strict_types=1);

namespace Pablo\App;

use Symfony\Component\Console\Application as SymfonyApplication;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;

/**
 * Standalone pablo console application. Its commands come from the DI
 * container, tagged `pablo.command` (see config/services.php), through the
 * lazy loader PabloCommandLoaderPass builds: a run instantiates only the
 * command it executes.
 */
final class ConsoleApplication extends SymfonyApplication
{
    /** Shown in a repository checkout, where Box never replaced the placeholder. */
    public const CHECKOUT_VERSION = 'dev';

    /**
     * Replaced by Box when the binary is built (a `replacements` entry set by
     * castor build:binary and .github/workflows/binary.yml): the release
     * name (YYYYMMDD or YYYYMMDD.N) for a released binary, else the first 7 characters
     * of the commit SHA. Left as is in a checkout.
     * A property, not a constant, so the check below is not constant-folded.
     */
    private static string $buildVersion = '@package_version@';

    public function __construct(?CommandLoaderInterface $commandLoader = null)
    {
        parent::__construct('pablo', self::version());
        if (null !== $commandLoader) {
            $this->setCommandLoader($commandLoader);
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
