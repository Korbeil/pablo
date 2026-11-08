<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Support\PabloPaths;

/**
 * Where `pablo system:install` puts things, resolved per call from HOME —
 * never at container compile time. The runtimes' own directories come from
 * RuntimeAgentFilesInterface.
 */
final class InstallPaths
{
    public static function binDir(): string
    {
        return PabloPaths::home().'/.local/bin';
    }

    /** The `pablo` command: the binary itself, or a shim from a checkout. */
    public static function binPath(): string
    {
        return self::binDir().'/pablo';
    }

    /** Whether $name is an executable file in one of the PATH directories. */
    public static function hasCommand(string $name): bool
    {
        foreach (explode(\PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ('' !== $dir && is_file($dir.'/'.$name) && is_executable($dir.'/'.$name)) {
                return true;
            }
        }

        return false;
    }
}
