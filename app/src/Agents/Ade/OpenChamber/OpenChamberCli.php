<?php

declare(strict_types=1);

namespace Pablo\Agents\Ade\OpenChamber;

/**
 * Tells OpenChamber's CLI from its desktop app: both install an
 * `openchamber` command. Only the CLI (the npm package @openchamber/web, a
 * Node script, which drives the running desktop instance when there is one)
 * has the `session` commands PABLO needs; the desktop app ignores them and
 * raises its window (or starts, when it isn't running). The file is read,
 * never run.
 */
final class OpenChamberCli
{
    public const PACKAGE = '@openchamber/web';

    public const INSTALL = 'npm i -g '.self::PACKAGE;

    /** ELF, then the Mach-O magics (32/64-bit, both byte orders, universal). */
    private const NATIVE_MAGICS = ["\x7fELF", "\xfe\xed\xfa\xce", "\xce\xfa\xed\xfe", "\xfe\xed\xfa\xcf", "\xcf\xfa\xed\xfe", "\xca\xfe\xba\xbe"];

    /** Whether the `openchamber` at $path is the desktop app (a native executable), not the CLI. */
    public static function isDesktopApp(string $path): bool
    {
        $real = realpath($path);
        if (false === $real) {
            return false;
        }
        if (str_ends_with(strtolower($real), '.appimage') || str_contains($real, '.app/Contents/MacOS/')) {
            return true;
        }
        $fd = @fopen($real, 'r');
        if (false === $fd) {
            return false;
        }
        $head = (string) fread($fd, 4);
        fclose($fd);

        return \in_array($head, self::NATIVE_MAGICS, true);
    }

    /** The doctor's verdict on the `openchamber` at $path: null when it is the CLI. */
    public static function refusal(string $path): ?string
    {
        if (!self::isDesktopApp($path)) {
            return null;
        }

        return "openchamber on your PATH is OpenChamber's desktop app ({$path}), not its CLI: agents fall back to headless";
    }
}
