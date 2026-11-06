<?php

declare(strict_types=1);

namespace Pablo\Tests\App;

use Pablo\App\ConsoleApplication;
use Pablo\App\Kernel;
use Pablo\Support\PabloPaths;
use PHPUnit\Framework\TestCase;

/**
 * Checkout mode (what the suite runs in): cache and logs stay under app/var,
 * PABLO_ENV defaults to dev, the version reads `dev`. Binary mode is covered
 * by the build's smoke tests (castor build:binary, .github/workflows/binary.yml).
 */
final class KernelTest extends TestCase
{
    private string|false $env;
    private string|false $home;

    protected function setUp(): void
    {
        $this->env = getenv('PABLO_ENV');
        $this->home = getenv('HOME');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->env ? 'PABLO_ENV' : 'PABLO_ENV='.$this->env);
        putenv(false === $this->home ? 'HOME' : 'HOME='.$this->home);
    }

    public function testACheckoutKeepsItsCacheUnderAppVar(): void
    {
        $this->assertFalse(PabloPaths::isPhar());
        $kernel = new Kernel('dev', true);

        $this->assertSame(\dirname(__DIR__, 2).'/var/cache/dev', $kernel->getCacheDir());
        $this->assertSame(\dirname(__DIR__, 2).'/var/log', $kernel->getLogDir());
    }

    public function testDefaultEnvironment(): void
    {
        putenv('PABLO_ENV');
        $this->assertSame('dev', Kernel::defaultEnvironment());

        putenv('PABLO_ENV=prod');
        $this->assertSame('prod', Kernel::defaultEnvironment());
    }

    public function testBinaryCacheRootIsResolvedFromHomeAtRuntime(): void
    {
        putenv('HOME=/somewhere/else');

        $this->assertSame('/somewhere/else/.pablo/cache/container', Kernel::binaryCacheRoot());
    }

    public function testACheckoutReportsADevVersion(): void
    {
        $this->assertSame(ConsoleApplication::CHECKOUT_VERSION, ConsoleApplication::version());
        $this->assertSame('dev', (new ConsoleApplication())->getVersion());
    }
}
