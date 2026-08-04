<?php

declare(strict_types=1);

namespace Pablo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire: the engine must stay Linux+macOS portable. Any reference to
 * Linux-only paths/services in src/ is a portability bug.
 */
final class PortabilityTest extends TestCase
{
    private const FORBIDDEN = ['systemctl', 'journalctl', '/etc/', '/proc/', '/opt/'];

    public function testSrcContainsNoPlatformSpecificReferences(): void
    {
        $files = [];
        foreach (glob(\dirname(__DIR__).'/src/**/*.php') ?: [] as $path) {
            $files[] = $path;
        }
        foreach (glob(\dirname(__DIR__).'/src/*.php') ?: [] as $path) {
            $files[] = $path;
        }
        $files = array_values(array_unique($files));

        $violations = [];
        foreach ($files as $path) {
            $content = (string) file_get_contents($path);
            foreach (self::FORBIDDEN as $needle) {
                if (str_contains($content, $needle)) {
                    $violations[] = basename($path).": contains '{$needle}'";
                }
            }
        }

        $this->assertSame([], $violations, "portability violations:\n".implode("\n", $violations));
    }
}
