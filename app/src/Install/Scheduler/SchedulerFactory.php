<?php

declare(strict_types=1);

namespace Pablo\Install\Scheduler;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;

/**
 * The scheduler of the platform PABLO runs on. Paths are resolved per call
 * (HOME), never at container compile time.
 */
final class SchedulerFactory
{
    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly string $osFamily = \PHP_OS_FAMILY,
    ) {
    }

    /** @throws PabloError on a platform PABLO does not support */
    public function create(): SchedulerInterface
    {
        $home = PabloPaths::home();

        return match ($this->osFamily) {
            'Linux' => new SystemdScheduler(
                $this->runner,
                PabloPaths::resourcesDir().'/systemd',
                $home.'/.config/systemd/user',
                self::userName(),
            ),
            'Darwin' => new LaunchdScheduler(
                $this->runner,
                PabloPaths::resourcesDir().'/launchd',
                $home.'/Library/LaunchAgents',
                PabloPaths::pabloDir().'/logs',
                $home,
                \function_exists('posix_getuid') ? posix_getuid() : (int) trim($this->runner->run(['id', '-u'])),
            ),
            default => throw new PabloError("unsupported platform: {$this->osFamily} (Linux and macOS are supported)"),
        };
    }

    private static function userName(): string
    {
        if (\function_exists('posix_geteuid') && \function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            if (\is_array($entry) && '' !== $entry['name']) {
                return $entry['name'];
            }
        }

        return (string) (getenv('USER') ?: getenv('LOGNAME') ?: '');
    }
}
