<?php

declare(strict_types=1);

namespace Pablo\Install;

use Pablo\Support\Files;
use Pablo\Support\PabloError;

/**
 * The symlinks `pablo system:install` creates under ~/.config/opencode,
 * ~/.claude and ~/.copilot — all pointing into ~/.pablo/runtime.
 *
 * A link is PABLO's ("ours") when it points into the runtime dir, or into a
 * PABLO repository checkout: what the clone install (bin/install.sh) used to
 * create, so an upgrade to the binary takes those over. Anything else that
 * exists at a destination is the user's and is never touched: install
 * refuses, uninstall leaves it.
 */
final class Links
{
    /** Where a clone install's links pointed, relative to its checkout. */
    private const LEGACY_LAYOUT = '#/(opencode/(agents|commands|skills)|claude/(agents|commands)|copilot/(agents|skills))/[^/]+/?$#';

    public function __construct(private readonly string $runtimeDir)
    {
    }

    /**
     * Point $dest at $source, replacing a link of ours (or a dangling one).
     *
     * @throws PabloError when $dest is a file or link PABLO does not own
     */
    public function link(string $source, string $dest): string
    {
        if (file_exists($dest) && !$this->isOurs($dest)) {
            $what = is_link($dest) ? 'a symlink PABLO did not create' : 'not a PABLO symlink';
            throw new PabloError("{$dest} exists and is {$what} — refusing to overwrite (originals stay untouched). Move it aside, then run pablo system:install again.");
        }
        if (is_link($dest) && readlink($dest) === $source) {
            return "linked {$dest}";
        }
        $dir = \dirname($dest);
        Files::ensureDir($dir);
        if (is_link($dest) && !@unlink($dest)) {
            throw new PabloError("cannot replace {$dest}");
        }
        if (!@symlink($source, $dest)) {
            throw new PabloError("cannot link {$dest} -> {$source}");
        }

        return "linked {$dest}";
    }

    /** Remove $dest when it is one of our links; true when something was removed. */
    public function removeIfOurs(string $dest): bool
    {
        if (!is_link($dest) || !$this->isOurs($dest)) {
            return false;
        }

        return Files::tryRemove($dest);
    }

    public function isOurs(string $dest): bool
    {
        if (!is_link($dest)) {
            return false;
        }
        $target = readlink($dest);
        if (false === $target) {
            return false;
        }
        if (!str_starts_with($target, '/')) {
            $target = \dirname($dest).'/'.$target;
        }

        $runtime = rtrim($this->runtimeDir, '/');
        $realRuntime = realpath($runtime);
        $realTarget = realpath($target);
        if (str_starts_with($target, $runtime.'/')
            || (false !== $realRuntime && false !== $realTarget && str_starts_with($realTarget, $realRuntime.'/'))) {
            return true;
        }

        if (null !== self::legacyCheckout(false !== $realTarget ? $realTarget : $target)) {
            return true;
        }

        // A dangling link into a checkout that was deleted since: ours when it
        // has the shape of what the clone install linked.
        return false === $realTarget && 1 === preg_match(self::LEGACY_LAYOUT, $target);
    }

    /**
     * The PABLO repository checkout $path lives in (a directory with
     * app/bin/pablo and opencode/), or null.
     */
    public static function legacyCheckout(string $path): ?string
    {
        $dir = $path;
        while ('/' !== $dir && '.' !== $dir && '' !== $dir) {
            $dir = \dirname($dir);
            if (is_file($dir.'/app/bin/pablo') && is_dir($dir.'/opencode')) {
                return $dir;
            }
        }

        return null;
    }
}
