<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Support\Files;
use Pablo\Support\PabloError;

/**
 * The `pablo` command in ~/.local/bin.
 *
 * From the standalone binary, the binary itself (copied there when it runs
 * from elsewhere, e.g. a fresh download). From a repository checkout
 * (contributors), a shim that execs `php <checkout>/app/bin/pablo`. Either
 * replaces the clone install's shim, which is how an old install migrates.
 */
final class PabloCommand
{
    /** What the clone install's shim (and a checkout's) looks like. */
    private const SHIM_PATTERN = "#^exec php '(.+)/app/bin/pablo' \"\\$@\"$#m";

    /**
     * @param string $binaryPath the running binary ('' when not running from one)
     * @param string $entryPoint app/bin/pablo of the checkout, for the shim
     *
     * @throws PabloError
     */
    public static function place(string $dest, string $binaryPath, string $entryPoint): string
    {
        if ('' !== $binaryPath) {
            $real = realpath($binaryPath);
            if (false !== $real && $real === realpath($dest)) {
                return "{$dest} is this binary";
            }
            $content = @file_get_contents($binaryPath);
            if (false === $content) {
                throw new PabloError("cannot read {$binaryPath}");
            }
            Files::write($dest, $content, 0o755);

            return "installed {$dest} (copied from {$binaryPath})";
        }

        Files::write($dest, "#!/usr/bin/env bash\nexec php '{$entryPoint}' \"\$@\"\n", 0o755);

        return "wrote {$dest} shim -> php {$entryPoint}";
    }

    /** The checkout a shim points into, or null when $path is no PABLO shim. */
    public static function shimCheckout(string $path): ?string
    {
        if (!is_file($path) || filesize($path) > 4096) {
            return null;
        }
        if (1 !== preg_match(self::SHIM_PATTERN, (string) @file_get_contents($path), $m)) {
            return null;
        }

        return $m[1];
    }

    /**
     * Remove $path when it is PABLO's: the running binary, or a shim.
     *
     * The running binary is only removed when this process exits: the phar
     * loads its classes lazily, reopening the file by path, so deleting it
     * now would make the next class the run needs (and Symfony's error
     * handler with it) fail to load.
     */
    public static function removeIfOurs(string $path, string $binaryPath): bool
    {
        $real = realpath($path);
        if ('' !== $binaryPath && false !== $real && $real === realpath($binaryPath)) {
            register_shutdown_function(static function () use ($path): void {
                @unlink($path);
            });

            return true;
        }
        if (null === self::shimCheckout($path)) {
            return false;
        }

        return @unlink($path);
    }
}
