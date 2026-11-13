<?php

declare(strict_types=1);

namespace Pablo\Tests;

/**
 * Collision-proof, self-cleaning temp paths for tests.
 *
 * `uniqid()` is only a microsecond clock: two processes (parallel suites from
 * several worktrees on a loaded machine) can get the same value and then
 * share — or fail to `mkdir` — the same directory. Here every name carries 64
 * random bits, and the path is created atomically (`mkdir` / `fopen('x')`
 * fail on an existing path), with a retry on collision, so the returned path
 * is always this caller's own.
 *
 * Everything lives under one root per process,
 * `<tmp>/pablo-tests-<random>/`, created on first use and removed with all
 * its content when that process ends (a shutdown function: it runs after
 * PHPUnit's own exit, whether tests passed, failed or errored). The root is
 * keyed by pid, so a forked child gets a root of its own and never removes
 * its parent's. Set `PABLO_KEEP_TEST_TMP=1` to keep the files for debugging:
 * the root is then printed on stderr at exit instead.
 */
final class TempDir
{
    public const KEEP_ENV = 'PABLO_KEEP_TEST_TMP';

    private const ATTEMPTS = 10;

    /** @var array<int, string> pid => this process' root */
    private static array $roots = [];

    private static bool $hooked = false;

    /** Creates a new empty directory `<root>/pablo-<prefix>-<random>` and returns its path. */
    public static function create(string $prefix): string
    {
        $root = self::root();
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $path = self::candidate($root, $prefix);
            if (@mkdir($path, 0o777, true)) { // checked: false on collision, retried
                return $path;
            }
        }

        throw new \RuntimeException(\sprintf('Cannot create a unique temp directory for "%s" in %s.', $prefix, $root));
    }

    /** Creates a new empty file `<root>/pablo-<prefix>-<random><suffix>` and returns its path. */
    public static function file(string $prefix, string $suffix = ''): string
    {
        $root = self::root();
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $path = self::candidate($root, $prefix).$suffix;
            $handle = @fopen($path, 'x'); // checked: false on collision, retried
            if (false !== $handle) {
                fclose($handle);

                return $path;
            }
        }

        throw new \RuntimeException(\sprintf('Cannot create a unique temp file for "%s" in %s.', $prefix, $root));
    }

    /**
     * The shutdown hook: removes this process' root, never throws.
     *
     * @internal public only to be callable by register_shutdown_function()
     */
    public static function cleanUp(): void
    {
        try {
            $pid = getmypid();
            $root = self::$roots[$pid] ?? null;
            if (null === $root) {
                return;
            }
            unset(self::$roots[$pid]);
            if (\in_array(getenv(self::KEEP_ENV), ['1', 'true', 'yes'], true)) {
                fwrite(\STDERR, \sprintf("%s set: test temp files kept in %s\n", self::KEEP_ENV, $root));

                return;
            }
            self::removeTree($root);
        } catch (\Throwable) {
            // Best-effort: a cleanup failure must never change the test result.
        }
    }

    /**
     * Removes $path and everything below it without following symlinks (a
     * link is unlinked, never entered), making read-only directories
     * writable first (git objects are 0444, some tests chmod), ignoring
     * whatever cannot be removed.
     */
    public static function removeTree(string $path): void
    {
        if (is_link($path) || (file_exists($path) && !is_dir($path))) {
            @unlink($path); // best-effort cleanup

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        @chmod($path, 0o700); // best-effort: lets us list and unlink its entries
        foreach (@scandir($path) ?: [] as $entry) { // best-effort: unreadable → left behind
            if ('.' !== $entry && '..' !== $entry) {
                self::removeTree($path.'/'.$entry);
            }
        }
        @rmdir($path); // best-effort cleanup
    }

    private static function root(): string
    {
        $pid = (int) getmypid();
        if (isset(self::$roots[$pid]) && is_dir(self::$roots[$pid])) {
            return self::$roots[$pid];
        }
        for ($i = 0; $i < self::ATTEMPTS; ++$i) {
            $root = self::candidate(sys_get_temp_dir(), 'tests');
            if (@mkdir($root, 0o777, true)) { // checked: false on collision, retried
                self::$roots[$pid] = $root;
                if (!self::$hooked) {
                    // Registered once: a forked child inherits it and only
                    // ever finds its own pid's root.
                    register_shutdown_function([self::class, 'cleanUp']);
                    self::$hooked = true;
                }

                return $root;
            }
        }

        throw new \RuntimeException(\sprintf('Cannot create a temp root in %s.', sys_get_temp_dir()));
    }

    private static function candidate(string $dir, string $prefix): string
    {
        return $dir.'/pablo-'.$prefix.'-'.bin2hex(random_bytes(8));
    }
}
