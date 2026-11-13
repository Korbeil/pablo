<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Checked file operations, plus directory listing and copying that also work
 * inside the phar.
 *
 * glob() only knows the local filesystem: on a `phar://` path it silently
 * returns nothing, which would make every bundled command and skill vanish
 * from the standalone binary. scandir()/copy() go through stream wrappers.
 *
 * A required operation goes through a throwing helper (ensureDir(), read(),
 * write()), so its failure names the path instead of surfacing later as an
 * obscure fopen/rename error; a deliberate best-effort one (logs, pidfiles,
 * caches) goes through a try* helper, which never throws.
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
            self::ensureDir($dest);
            foreach (self::list($source) as $child) {
                self::copy($child, $dest.'/'.basename($child));
            }

            return;
        }

        self::ensureDir(\dirname($dest));
        self::write($dest, self::read($source));
    }

    /**
     * Write $content to $path (parents created), replacing a symlink rather
     * than writing through it: an older install linked these paths into a
     * repository checkout, and writing through the link would edit the
     * checkout.
     *
     * Atomic: a unique temp file in the same directory, renamed over $path; a
     * short write or failed rename throws and leaves $path intact. Identical
     * content is not rewritten.
     *
     * @throws PabloError
     */
    public static function write(string $path, string $content, ?int $mode = null): void
    {
        self::ensureDir(\dirname($path));
        if (is_link($path) && !@unlink($path)) {
            throw new PabloError("cannot replace the symlink {$path}");
        }
        if (is_file($path) && self::tryRead($path) === $content) {
            if (null !== $mode) {
                @chmod($path, $mode);
            }

            return;
        }
        $tmp = $path.'.pablo-tmp-'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, $content) !== \strlen($content)) {
            self::tryRemove($tmp);
            throw new PabloError("cannot write {$path} (temp file {$tmp}): disk full or not writable?");
        }
        if (null !== $mode) {
            @chmod($tmp, $mode);
        } elseif (is_file($path) && false !== ($perms = @fileperms($path))) {
            @chmod($tmp, $perms & 0o7777);
        }
        if (!@rename($tmp, $path)) {
            self::tryRemove($tmp);
            throw new PabloError("cannot write {$path}: rename from {$tmp} failed");
        }
    }

    /**
     * Create $dir and its parents unless it exists. Race-safe: a directory
     * created concurrently by another process is fine.
     *
     * @throws PabloError naming $dir when it cannot be created
     */
    public static function ensureDir(string $dir): void
    {
        if (!self::tryEnsureDir($dir)) {
            throw new PabloError("cannot create the directory {$dir}");
        }
    }

    /** Best-effort ensureDir(): whether $dir exists afterwards, never throws. */
    public static function tryEnsureDir(string $dir): bool
    {
        return is_dir($dir) || @mkdir($dir, 0o777, true) || is_dir($dir);
    }

    /** @throws PabloError naming $path when it cannot be read */
    public static function read(string $path): string
    {
        $content = self::tryRead($path);
        if (null === $content) {
            throw new PabloError("cannot read {$path}");
        }

        return $content;
    }

    /** The content of $path, or null when it is missing or unreadable. */
    public static function tryRead(string $path): ?string
    {
        // Suppressed on purpose: a missing file is an expected outcome here.
        $content = @file_get_contents($path);

        return false === $content ? null : $content;
    }

    /**
     * Best-effort removal of a file or symlink (not a directory: see
     * remove()); whether it is gone, never throws.
     */
    public static function tryRemove(string $path): bool
    {
        // Suppressed on purpose: a missing file is already the wanted state.
        return @unlink($path) || (!is_link($path) && !file_exists($path));
    }

    /** Remove a file, a symlink or a directory tree; a missing path is fine. */
    public static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            self::tryRemove($path);

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
