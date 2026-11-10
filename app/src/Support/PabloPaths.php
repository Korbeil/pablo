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
    public static function home(): string
    {
        return getenv('HOME') ?: '~';
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
