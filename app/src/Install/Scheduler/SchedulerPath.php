<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

use Pablo\Agents\Ade\Detached;

/**
 * The PATH the background services run with, computed at `system:install`
 * time from the installing user's own PATH: a service manager starts them
 * with a bare PATH (systemd --user's, launchd's /usr/bin:/bin:/usr/sbin:/sbin)
 * that misses the CLIs installed per user (nvm, pnpm, ~/.opencode/bin,
 * Homebrew, …), and then every agent silently falls back to headless.
 *
 * Order: the `scheduler.path` extras of ~/.pablo/config.yaml, ~/.local/bin
 * (the pablo command) and ~/bin, the directory of every CLI found on the
 * current PATH (first match, as the shell would pick it) that is not a system
 * directory, then the system directories in their usual order — so a
 * per-user CLI wins over a same-named system program (e.g. GNOME's
 * /usr/bin/orca). De-duplicated, absolute directories only.
 *
 * Pure: HOME, PATH and the OS are passed in, never read at container
 * compile time.
 */
final class SchedulerPath
{
    /**
     * CLIs the dispatcher may call whatever the runtime/ADE: git and the
     * trackers' CLIs, `node` for npm/pnpm shims (OpenChamber, Orca's shim),
     * the tools of a detached headless run (including the session helpers:
     * `setsid`, else `perl` on macOS), and `brew` so Homebrew's prefix (where
     * gh/acli/… usually are on macOS) is found wherever it lives.
     */
    public const TOOLS = ['git', 'gh', 'node', 'acli', 'linear', 'bash', ...Detached::SESSION_HELPERS, 'script', 'brew'];

    private const SYSTEM = [
        'Linux' => ['/usr/local/sbin', '/usr/local/bin', '/usr/sbin', '/usr/bin', '/sbin', '/bin', '/snap/bin'],
        'Darwin' => ['/usr/local/bin', '/usr/bin', '/bin', '/usr/sbin', '/sbin'],
    ];

    /**
     * @param list<string> $clis  command names (or absolute paths) to locate on $currentPath
     * @param list<string> $extra directories prepended as-is (`~/` expanded to $home)
     */
    public static function build(string $home, string $currentPath, array $clis, array $extra = [], string $osFamily = \PHP_OS_FAMILY): string
    {
        $system = self::SYSTEM[$osFamily] ?? self::SYSTEM['Linux'];
        $dirs = [];
        foreach ($extra as $dir) {
            $dirs[] = self::expand($dir, $home);
        }
        $dirs[] = $home.'/.local/bin';
        $dirs[] = $home.'/bin';
        foreach ($clis as $cli) {
            $dir = self::locate($cli, $currentPath);
            if (null !== $dir && !\in_array($dir, $system, true)) {
                $dirs[] = $dir;
            }
        }

        $path = [];
        foreach ([...$dirs, ...$system] as $dir) {
            $dir = rtrim($dir, '/');
            if (str_starts_with($dir, '/') && !\in_array($dir, $path, true)) {
                $path[] = $dir;
            }
        }

        return implode(':', $path);
    }

    /** The directory holding $cli on $currentPath (its first executable match), or null. */
    public static function locate(string $cli, string $currentPath): ?string
    {
        if ('' === $cli) {
            return null;
        }
        if (str_contains($cli, '/')) {
            return str_starts_with($cli, '/') && is_file($cli) && is_executable($cli) ? \dirname($cli) : null;
        }
        foreach (explode(':', $currentPath) as $dir) {
            // Relative entries (".", "") depend on the cwd: never baked into a unit.
            if (!str_starts_with($dir, '/')) {
                continue;
            }
            $file = rtrim($dir, '/').'/'.$cli;
            if (is_file($file) && is_executable($file)) {
                return rtrim($dir, '/');
            }
        }

        return null;
    }

    private static function expand(string $dir, string $home): string
    {
        if ('~' === $dir) {
            return $home;
        }

        return str_starts_with($dir, '~/') ? $home.substr($dir, 1) : $dir;
    }
}
