<?php

declare(strict_types=1);

namespace Pablo\Update;

use Pablo\Support\PabloError;

/**
 * The release asset name for a platform: pablo-<os>-<arch>, the same names
 * .github/workflows/binary.yml publishes and site/installer downloads.
 */
final class Target
{
    public const TARGETS = ['linux-x86_64', 'linux-aarch64', 'macos-arm64', 'macos-x86_64'];

    /** @throws PabloError on a platform PABLO publishes no binary for */
    public static function current(): string
    {
        return self::for(\PHP_OS_FAMILY, php_uname('m'));
    }

    /** @throws PabloError */
    public static function for(string $osFamily, string $machine): string
    {
        $target = match ($osFamily) {
            'Linux' => match ($machine) {
                'x86_64', 'amd64' => 'linux-x86_64',
                'aarch64', 'arm64' => 'linux-aarch64',
                default => null,
            },
            'Darwin' => match ($machine) {
                'arm64', 'aarch64' => 'macos-arm64',
                'x86_64' => 'macos-x86_64',
                default => null,
            },
            default => null,
        };
        if (null === $target) {
            throw new PabloError("no PABLO binary for {$osFamily} on {$machine} (supported: ".implode(', ', self::TARGETS).')');
        }

        return $target;
    }

    public static function asset(string $target): string
    {
        return 'pablo-'.$target;
    }
}
