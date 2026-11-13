<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

use Pablo\Support\Files;
use Pablo\Support\PabloError;

/**
 * Writing and removing the marked service files both schedulers manage.
 */
final class ServiceFiles
{
    public static function marker(string $name): string
    {
        return 'marker: '.$name;
    }

    /** PABLO's when it is a symlink (clone install) or carries its marker line. */
    public static function isOurs(string $path, string $name): bool
    {
        if (is_link($path)) {
            return true;
        }

        return is_file($path) && str_contains((string) Files::tryRead($path), self::marker($name));
    }

    /**
     * Write $content to $path unless a file of the user's is there.
     *
     * @throws PabloError
     */
    public static function write(string $path, string $name, string $content): void
    {
        if ((file_exists($path) || is_link($path)) && !self::isOurs($path, $name)) {
            throw new PabloError("{$path} exists and was not written by PABLO — refusing to overwrite");
        }
        if (!str_contains($content, self::marker($name))) {
            throw new PabloError("the bundled {$name} has no '".self::marker($name)."' line");
        }
        Files::write($path, $content);
    }

    /**
     * Read a bundled file (possibly inside the binary).
     *
     * @throws PabloError
     */
    public static function bundled(string $path): string
    {
        $content = @file_get_contents($path);
        if (false === $content) {
            throw new PabloError("cannot read the bundled {$path}");
        }

        return $content;
    }

    /** Remove $path when it is ours; true when something was removed. */
    public static function removeIfOurs(string $path, string $name): bool
    {
        if (!self::isOurs($path, $name)) {
            return false;
        }

        return Files::tryRemove($path);
    }
}
