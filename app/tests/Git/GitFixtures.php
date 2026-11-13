<?php

declare(strict_types=1);

namespace Pablo\Tests\Git;

use Pablo\Tests\TempDir;

/**
 * Git fixtures built once per process, handed to each test as a fresh copy.
 *
 * Building a bare origin + clones from scratch costs a dozen git processes;
 * copying the files costs none. `get($key, $dest, $build)` runs `$build` the
 * first time a key is asked for, into a template directory of its own
 * (TempDir, so it is cleaned up with the other temp dirs), and from then on
 * copies that template into `$dest`. The template is never handed out and
 * never touched again, so each test gets its own repositories.
 *
 * Git writes absolute paths into a repository: a clone's remote URL in
 * `.git/config`, a linked worktree's `.git` file and its
 * `.git/worktrees/<name>/gitdir`. The copy rewrites every occurrence of the
 * template's path (outside the object store, which never holds one) into
 * `$dest`'s, so remotes, fetches, pushes and `git worktree` all act on the
 * copy. `$build` must therefore create everything under the directory it is
 * given and nothing outside it. The `hooks/*.sample` files git seeds every
 * repository with are not copied: git never runs them.
 *
 * Per process, not shared between processes: parallel workers each build
 * their own, so no template is ever read while it is being built.
 */
final class GitFixtures
{
    /** @var array<string, string> key => template directory */
    private static array $templates = [];

    /**
     * Copies the fixture `$key` into `$dest` (created if missing; existing
     * entries are kept), building it with `$build(<template dir>)` first if
     * this process has not yet.
     *
     * @param callable(string): void $build
     */
    public static function get(string $key, string $dest, callable $build): void
    {
        self::copy(self::template($key, $build), $dest);
    }

    /**
     * The template directory of `$key`, built on first use. Only for tests
     * of this class: a test must work on a copy.
     *
     * @param callable(string): void $build
     */
    public static function template(string $key, callable $build): string
    {
        if (!isset(self::$templates[$key])) {
            $dir = TempDir::create('git-template-'.$key);
            $build($dir);
            self::$templates[$key] = $dir;
        }

        return self::$templates[$key];
    }

    private static function copy(string $template, string $dest): void
    {
        if (!is_dir($dest) && !mkdir($dest, 0o777, true) && !is_dir($dest)) {
            throw new \RuntimeException("Cannot create {$dest}.");
        }
        // Git may store the resolved path too (macOS: /var -> /private/var);
        // strtr() tries the longest key first.
        $replace = [];
        $realTemplate = realpath($template);
        $realDest = realpath($dest);
        if (false !== $realTemplate && false !== $realDest && $realTemplate !== $template) {
            $replace[$realTemplate] = $realDest;
        }
        $replace[$template] = $dest;

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($template, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );
        $prefixLength = \strlen($template);
        /** @var \SplFileInfo $item */
        foreach ($items as $item) {
            $source = $item->getPathname();
            $relative = substr($source, $prefixLength);
            $target = $dest.$relative;
            if ($item->isLink()) {
                throw new \RuntimeException("Git fixtures cannot hold a symlink: {$source}.");
            }
            if ($item->isDir()) {
                if (!is_dir($target) && !mkdir($target, $item->getPerms() & 0o777) && !is_dir($target)) {
                    throw new \RuntimeException("Cannot create {$target}.");
                }
                continue;
            }
            if (str_ends_with($relative, '.sample') && str_contains($relative, '/hooks/')) {
                continue; // never run by git, and half the files to copy
            }
            $content = file_get_contents($source);
            if (false === $content) {
                throw new \RuntimeException("Cannot read {$source}.");
            }
            if (!str_contains($relative, '/objects/')) {
                $content = strtr($content, $replace);
            }
            if (false === file_put_contents($target, $content)) {
                throw new \RuntimeException("Cannot write {$target}.");
            }
            chmod($target, $item->getPerms() & 0o777);
        }
    }
}
