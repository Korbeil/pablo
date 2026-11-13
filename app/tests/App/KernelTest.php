<?php

declare(strict_types=1);

namespace Pablo\Tests\App;

use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use Pablo\Support\PabloPaths;
use Pablo\Tests\SandboxedEnv;
use PHPUnit\Framework\TestCase;

/**
 * Checkout mode (what the suite runs in): cache and logs stay under app/var,
 * PABLO_ENV defaults to dev, the version reads `dev`. Binary mode is covered
 * by the build's smoke tests (castor build:binary, .github/workflows/binary.yml).
 */
final class KernelTest extends TestCase
{
    use SandboxedEnv;

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    public function testACheckoutKeepsItsCacheUnderAppVar(): void
    {
        $this->assertFalse(PabloPaths::isPhar());
        $kernel = new Kernel('dev', true);

        $this->assertSame(\dirname(__DIR__, 2).'/var/cache/dev', $kernel->getCacheDir());
        $this->assertSame(\dirname(__DIR__, 2).'/var/log', $kernel->getLogDir());
    }

    public function testEachParallelTestWorkerHasItsOwnTestCache(): void
    {
        $this->setEnv('TEST_TOKEN', '3');
        $this->assertSame(\dirname(__DIR__, 2).'/var/cache/test-3', (new Kernel('test', true))->getCacheDir());
        $this->assertSame(\dirname(__DIR__, 2).'/var/cache/dev', (new Kernel('dev', true))->getCacheDir(), 'only the test env is split');

        $this->setEnv('TEST_TOKEN', '../x');
        $this->assertSame(\dirname(__DIR__, 2).'/var/cache/test', (new Kernel('test', true))->getCacheDir());

        $this->setEnv('TEST_TOKEN', null);
        $this->assertSame(\dirname(__DIR__, 2).'/var/cache/test', (new Kernel('test', true))->getCacheDir());
    }

    public function testDefaultEnvironment(): void
    {
        $this->setEnv('PABLO_ENV', null);
        $this->assertSame('dev', Kernel::defaultEnvironment());

        $this->setEnv('PABLO_ENV', 'prod');
        $this->assertSame('prod', Kernel::defaultEnvironment());
    }

    public function testBinaryCacheRootIsResolvedFromHomeAtRuntime(): void
    {
        $this->setEnv('HOME', '/somewhere/else');

        $this->assertSame('/somewhere/else/.pablo/cache/container', Kernel::binaryCacheRoot());
    }

    public function testACheckoutReportsADevVersion(): void
    {
        $this->assertSame(ConsoleApplication::CHECKOUT_VERSION, ConsoleApplication::version());
        $this->assertSame('dev', (new ConsoleApplication())->getVersion());
    }
}
