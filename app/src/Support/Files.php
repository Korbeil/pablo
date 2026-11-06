<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Directory listing and copying that also work inside the phar.
 *
 * glob() only knows the local filesystem: on a `phar://` path it silently
 * returns nothing, which would make every bundled command and skill vanish
 * from the standalone binary. scandir()/copy() go through stream wrappers.
 */
final class Files
{
    /**
     * Entries of $dir matching the fnmatch() $pattern, as full paths, sorted.
     * A missing directory lists nothing.
     *
     * @return list<string>
     */
    public static function list(string $dir, string $pattern = '*', bool $dirsOnly = false): array
    {
        $dir = rtrim($dir, '/');
        $entries = is_dir($dir) ? @scandir($dir) : false;
        if (false === $entries) {
            return [];
        }

        $paths = [];
        foreach ($entries as $entry) {
            if ('.' === $entry || '..' === $entry || !fnmatch($pattern, $entry)) {
                continue;
            }
            $path = $dir.'/'.$entry;
            if ($dirsOnly && !is_dir($path)) {
                continue;
            }
            $paths[] = $path;
        }
        sort($paths);

        return $paths;
    }

    /**
     * Copy $source (a file or a directory, recursively) to $dest, creating
     * parent directories. Existing files are overwritten; files present in
     * $dest but not in $source are left alone.
     *
     * @throws PabloError when something cannot be created or copied
     */
    public static function copy(string $source, string $dest): void
    {
        if (is_dir($source)) {
            self::mkdir($dest);
            foreach (self::list($source) as $child) {
                self::copy($child, $dest.'/'.basename($child));
            }

            return;
        }

        self::mkdir(\dirname($dest));
        $content = @file_get_contents($source);
        if (false === $content) {
            throw new PabloError("cannot read {$source}");
        }
        self::write($dest, $content);
    }

    /**
     * Write $content to $path (parents created), replacing a symlink rather
     * than writing through it: an older install linked these paths into a
     * repository checkout, and writing through the link would edit the
     * checkout.
     *
     * @throws PabloError
     */
    public static function write(string $path, string $content, ?int $mode = null): void
    {
        self::mkdir(\dirname($path));
        if (is_link($path) && !@unlink($path)) {
            throw new PabloError("cannot replace the symlink {$path}");
        }
        if (is_file($path) && @file_get_contents($path) === $content) {
            if (null !== $mode) {
                @chmod($path, $mode);
            }

            return;
        }
        $tmp = $path.'.pablo-tmp-'.getmypid();
        if (false === @file_put_contents($tmp, $content)) {
            throw new PabloError("cannot write {$path}");
        }
        if (null !== $mode) {
            @chmod($tmp, $mode);
        } elseif (is_file($path) && false !== ($perms = @fileperms($path))) {
            @chmod($tmp, $perms & 0o7777);
        }
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new PabloError("cannot write {$path}");
        }
    }

    /** @throws PabloError */
    public static function mkdir(string $dir): void
    {
        if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new PabloError("cannot create {$dir}");
        }
    }

    /** Remove a file, a symlink or a directory tree; a missing path is fine. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        // fnmatch()'s '*' matches dotfiles too (no FNM_PERIOD).
        foreach (self::list($path) as $child) {
            self::remove($child);
        }
        @rmdir($path);
    }
}
