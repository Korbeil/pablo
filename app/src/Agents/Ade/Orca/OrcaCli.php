<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\Orca;

/**
 * The command running the Orca CLI: `orca-ide` on Linux, `orca` elsewhere.
 *
 * The Orca app installs its Linux CLI as `orca-ide` because GNOME's Orca
 * screen reader owns /usr/bin/orca (bare `orca` there answers every call
 * with "The following are not valid", and may start speech). Orca's own
 * ORCA_CLI_COMMAND (exported in its managed WSL sessions) wins, as in Orca's
 * orca-cli skill. Read per call, never at container compile time.
 */
final class OrcaCli
{
    public const ENV = 'ORCA_CLI_COMMAND';
    public const LINUX = 'orca-ide';
    public const DEFAULT = 'orca';

    public static function command(string $osFamily = \PHP_OS_FAMILY): string
    {
        $env = getenv(self::ENV);
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return 'Linux' === $osFamily ? self::LINUX : self::DEFAULT;
    }
}
