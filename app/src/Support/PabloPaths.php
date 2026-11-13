<?php

declare(strict_types=1);

namespace Pablo\Support;

/**
 * Where PABLO's own files live, resolved per call — never at container compile
 * time: the compiled container is cached, so a compile-time HOME / PABLO_*
 * would freeze on whichever process warmed it.
 *
 * Two layouts share this code:
 *  - repository checkout (`php app/bin/pablo`): resources are read from the
 *    checkout;
 *  - standalone binary (a Box phar combined with a static PHP, see
 *    app/box.json): the phar mirrors the repository layout, so the same
 *    relative paths resolve inside `phar://…`. Nothing can be written there,
 *    which is why every rendered file goes to ~/.pablo/runtime.
 */
final class PabloPaths
{
    /**
     * The user's home directory (HOME), read on every call.
     *
     * @throws PabloError when HOME is unset or empty: every PABLO path hangs off
     *                    it, and guessing would scatter files under a relative "~/"
     */
    public static function home(): string
    {
        $home = getenv('HOME');
        if (false === $home || '' === $home) {
            throw new PabloError('HOME is not set: PABLO keeps its files under ~/.pablo and needs it to find your home directory');
        }

        return $home;
    }

    /**
     * Expands a leading "~" or "~/" to HOME; any other path is returned as-is.
     * "~user/…" is deliberately left untouched: PABLO only knows the current
     * user's home, and that syntax belongs to the shell.
     *
     * @throws PabloError when the path needs HOME and it is unset
     */
    public static function expandTilde(string $path): string
    {
        if ('~' === $path) {
            return self::home();
        }
        if (str_starts_with($path, '~/')) {
            return self::home().substr($path, 1);
        }

        return $path;
    }

    /**
     * The reverse of expandTilde(): a path inside HOME written as "~/…" (HOME
     * itself as "~"); anything else — or any path when HOME is unset — as-is.
     */
    public static function contractTilde(string $path): string
    {
        $home = rtrim((string) getenv('HOME'), '/');
        if ('' === $home) {
            return $path;
        }
        if ($path === $home) {
            return '~';
        }
        if (str_starts_with($path, $home.'/')) {
            return '~'.substr($path, \strlen($home));
        }

        return $path;
    }

    /** ~/.pablo: state, stamps, worktrees, logs, config, caches. */
    public static function pabloDir(): string
    {
        return self::home().'/.pablo';
    }

    /**
     * ~/.pablo/<relative> (~/.pablo itself for ''), unless the environment variable $override is set
     * (non-empty): then its value, as-is. Read on every call.
     */
    public static function pabloPath(string $relative, ?string $override = null): string
    {
        if (null !== $override) {
            $value = getenv($override);
            if (false !== $value && '' !== $value) {
                return $value;
            }
        }

        return '' === $relative ? self::pabloDir() : self::pabloDir().'/'.$relative;
    }

    /** ~/.pablo/logs (or PABLO_LOGS_DIR): sync history, last rebase logs, service and hook logs. */
    public static function logsDir(): string
    {
        return self::pabloPath('logs', 'PABLO_LOGS_DIR');
    }

    /** ~/.pablo/agents (or PABLO_AGENTS_DIR): agent pidfiles, run logs and launch locks. */
    public static function agentsDir(): string
    {
        return self::pabloPath('agents', 'PABLO_AGENTS_DIR');
    }

    /**
     * ~/.pablo/runtime (or PABLO_RUNTIME_DIR): every file PABLO renders or
     * extracts for the agent runtimes — opencode/{agents,commands,skills},
     * claude/{agents,commands} + agents.json, copilot/{agents,skills}. The
     * links PABLO creates in ~/.config/opencode, ~/.claude and ~/.copilot
     * point here, never into a checkout or the binary.
     */
    public static function runtimeDir(): string
    {
        $override = getenv('PABLO_RUNTIME_DIR');
        if (false !== $override && '' !== $override) {
            return rtrim($override, '/');
        }

        return self::pabloDir().'/runtime';
    }

    /**
     * Root of the bundled, read-only resources: the repository checkout, or
     * the inside of the phar (same layout: opencode/, systemd/, launchd/,
     * examples/, app/).
     */
    public static function resourcesDir(): string
    {
        return \dirname(__DIR__, 3);
    }

    /** True when running from the standalone binary (a phar). */
    public static function isPhar(): bool
    {
        return '' !== self::pharPath();
    }

    /** Path of the running phar / binary on disk, '' outside one. */
    public static function pharPath(): string
    {
        if (!class_exists(\Phar::class, false)) {
            return '';
        }

        return \Phar::running(false);
    }
}
