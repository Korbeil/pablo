<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Support\PabloPaths;
use Symfony\Component\Process\ExecutableFinder;

/**
 * Which executable is the Orca IDE's CLI. Resolved per call.
 *
 * `orca` is ambiguous on Linux: GNOME ships its screen reader as
 * /usr/bin/orca, and the `orca` dispatcher Orca installs in ~/.local/bin
 * deliberately hands over to any other `orca` on the PATH when it does not
 * run inside an Orca terminal (so as not to hijack the screen reader). From a
 * plain shell, `orca worktree show …` then reaches the screen reader, fails —
 * or hangs reaching for the desktop — and every agent falls back to headless.
 *
 * So, in order: PABLO_ORCA_BIN; `orca-ide` (installed next to `orca`, never
 * ambiguous); Orca's Linux CLI shim; and only then an `orca` of the PATH that
 * is not the screen reader.
 */
final class OrcaCli
{
    /** The shim Orca writes on Linux (it execs the real CLI). */
    public const LINUX_SHIM = '.config/orca/linux-orca-cli-shim/orca';

    /**
     * The Orca CLI's path, or null when only the screen reader (or nothing)
     * answers to the name.
     *
     * @param (callable(string): ?string)|null $which          PATH lookup (tests)
     * @param bool                             $verifyOverride also look a bare PABLO_ORCA_BIN up on the PATH
     *                                                         (the doctor reports a missing one)
     */
    public static function find(?callable $which = null, bool $verifyOverride = false): ?string
    {
        $which ??= static fn (string $bin): ?string => (new ExecutableFinder())->find($bin);

        $override = getenv('PABLO_ORCA_BIN');
        if (false !== $override && '' !== $override) {
            if (!$verifyOverride || str_contains($override, '/')) {
                return $override;
            }
            $path = $which($override);

            return null !== $path && !self::isScreenReader($path) ? $path : null;
        }
        $ide = $which('orca-ide');
        if (null !== $ide) {
            return $ide;
        }
        $shim = PabloPaths::home().'/'.self::LINUX_SHIM;
        if (is_file($shim) && is_executable($shim)) {
            return $shim;
        }
        $orca = $which('orca');

        return null !== $orca && !self::isScreenReader($orca) ? $orca : null;
    }

    /** The command to run: the Orca CLI, else plain `orca` (whose failure the callers handle). */
    public static function command(?callable $which = null): string
    {
        return self::find($which) ?? 'orca';
    }

    /**
     * GNOME's screen reader is a Python script; Orca's CLI and its wrappers
     * are shell scripts or binaries. A file that cannot be read is not judged.
     */
    public static function isScreenReader(string $path): bool
    {
        $head = @file_get_contents($path, false, null, 0, 256);

        return false !== $head && str_starts_with($head, '#!') && str_contains(strtok($head, "\n") ?: '', 'python');
    }
}
