<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\PabloPaths;
use PHPUnit\Framework\TestCase;

final class PabloPathsTest extends TestCase
{
    private string|false $home;
    private string|false $runtime;

    protected function setUp(): void
    {
        $this->home = getenv('HOME');
        $this->runtime = getenv('PABLO_RUNTIME_DIR');
    }

    protected function tearDown(): void
    {
        putenv(false === $this->home ? 'HOME' : 'HOME='.$this->home);
        putenv(false === $this->runtime ? 'PABLO_RUNTIME_DIR' : 'PABLO_RUNTIME_DIR='.$this->runtime);
    }

    public function testRuntimeDirFollowsHomeAndItsOverride(): void
    {
        putenv('HOME=/h');
        putenv('PABLO_RUNTIME_DIR');
        $this->assertSame('/h/.pablo/runtime', PabloPaths::runtimeDir());

        putenv('PABLO_RUNTIME_DIR=/r/');
        $this->assertSame('/r', PabloPaths::runtimeDir());
    }

    public function testPabloPathFollowsHomeAndItsOverride(): void
    {
        putenv('HOME=/h');
        putenv('PABLO_TEST_DIR');
        $this->assertSame('/h/.pablo/state', PabloPaths::pabloPath('state'));
        $this->assertSame('/h/.pablo/state', PabloPaths::pabloPath('state', 'PABLO_TEST_DIR'));
        $this->assertSame('/h/.pablo', PabloPaths::pabloPath(''));

        putenv('PABLO_TEST_DIR=');
        $this->assertSame('/h/.pablo/state', PabloPaths::pabloPath('state', 'PABLO_TEST_DIR'), 'an empty override is unset');

        putenv('PABLO_TEST_DIR=/elsewhere/');
        $this->assertSame('/elsewhere/', PabloPaths::pabloPath('state', 'PABLO_TEST_DIR'));
        putenv('PABLO_TEST_DIR');
    }

    public function testResourcesAreTheRepositoryRootOutsideThePhar(): void
    {
        $this->assertSame(\dirname(__DIR__, 3), PabloPaths::resourcesDir());
        $this->assertDirectoryExists(PabloPaths::resourcesDir().'/opencode/agents');
        $this->assertFileExists(PabloPaths::resourcesDir().'/examples/config.yaml');
        $this->assertSame('', PabloPaths::pharPath());
    }
}
