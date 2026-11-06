<?php

declare(strict_types=1);

namespace Pablo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire: the engine must stay Linux+macOS portable. Any reference to
 * Linux-only paths/services in src/ is a portability bug.
 *
 * The single exception is the installer's per-platform scheduler: driving
 * systemd is the whole point of SystemdScheduler, and SchedulerFactory only
 * ever builds it on Linux (launchd on macOS).
 */
final class PortabilityTest extends TestCase
{
    private const FORBIDDEN = ['systemctl', 'journalctl', '/etc/', '/proc/', '/opt/'];

    /** Relative to src/. */
    private const PLATFORM_SPECIFIC = [
        'Install/Scheduler/SystemdScheduler.php',
    ];

    public function testSrcContainsNoPlatformSpecificReferences(): void
    {
        $src = \dirname(__DIR__).'/src';
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($src, \FilesystemIterator::SKIP_DOTS));

        $violations = [];
        $scanned = 0;
        foreach ($files as $file) {
            \assert($file instanceof \SplFileInfo);
            if ('php' !== $file->getExtension()) {
                continue;
            }
            $relative = substr($file->getPathname(), \strlen($src) + 1);
            if (\in_array($relative, self::PLATFORM_SPECIFIC, true)) {
                continue;
            }
            ++$scanned;
            $content = (string) file_get_contents($file->getPathname());
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($content, $needle)) {
                    $violations[] = "{$relative}: contains '{$needle}'";
                }
            }
        }

        $this->assertGreaterThan(100, $scanned, 'the scan must reach every src/ subdirectory');
        $this->assertSame([], $violations, "portability violations:\n".implode("\n", $violations));
    }

    public function testThePlatformSpecificSchedulerIsOnlyBuiltOnLinux(): void
    {
        $factory = (string) file_get_contents(\dirname(__DIR__).'/src/Install/Scheduler/SchedulerFactory.php');

        $this->assertMatchesRegularExpression("/'Linux' => new SystemdScheduler\\(/", $factory);
        foreach (self::PLATFORM_SPECIFIC as $relative) {
            $this->assertFileExists(\dirname(__DIR__).'/src/'.$relative);
        }
    }
}
