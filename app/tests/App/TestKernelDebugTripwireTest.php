<?php

declare(strict_types=1);

namespace Pablo\Tests\App;

use PHPUnit\Framework\TestCase;

/**
 * Every kernel a test boots must run with debug on: only a debug kernel
 * checks its cached container (var/cache/test) for freshness. A non-debug
 * one keeps a stale container after a rebase that changes a service's
 * constructor, and the tests fail with TypeErrors from var/cache/test until
 * someone deletes it (CI never sees it: it starts from an empty cache).
 */
final class TestKernelDebugTripwireTest extends TestCase
{
    public function testKernelTestCasesBootInDebug(): void
    {
        // KernelTestCase::createKernel() reads $_ENV before $_SERVER.
        $this->assertSame('1', $_ENV['APP_DEBUG'] ?? null, 'phpunit.xml must force <env name="APP_DEBUG" value="1"/>');
    }

    public function testNoTestBuildsANonDebugKernel(): void
    {
        $offenders = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(\dirname(__DIR__), \FilesystemIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || 'php' !== $file->getExtension() || __FILE__ === $file->getPathname()) {
                continue;
            }
            $code = (string) file_get_contents($file->getPathname());
            // A Kernel constructed with anything but a literal true as debug.
            if (preg_match_all('/new\s+(?:\\\\?Pablo\\\\App\\\\)?Kernel\s*\(\s*[^,()]+,\s*+(?!true\s*\))[^)]*\)/', $code, $m)) {
                foreach ($m[0] as $call) {
                    $offenders[] = $file->getPathname().': '.$call;
                }
            }
            if (preg_match('/[\'"]debug[\'"]\s*=>\s*false/', $code)) {
                $offenders[] = $file->getPathname().": 'debug' => false";
            }
        }

        $this->assertSame([], $offenders, 'boot test kernels with debug=true (see AGENTS.md, Testing)');
    }
}
