<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use Pablo\Tests\SandboxedEnv;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PabloPathsTest extends TestCase
{
    use SandboxedEnv;

    protected function tearDown(): void
    {
        $this->restoreEnv();
    }

    public function testRuntimeDirFollowsHomeAndItsOverride(): void
    {
        $this->setEnv('HOME', '/h');
        $this->setEnv('PABLO_RUNTIME_DIR', null);
        $this->assertSame('/h/.pablo/runtime', PabloPaths::runtimeDir());

        $this->setEnv('PABLO_RUNTIME_DIR', '/r/');
        $this->assertSame('/r', PabloPaths::runtimeDir());
    }

    public function testPabloPathFollowsHomeAndItsOverride(): void
    {
        $this->setEnv('HOME', '/h');
        $this->setEnv('PABLO_TEST_DIR', null);
        $this->assertSame('/h/.pablo/state', PabloPaths::pabloPath('state'));
        $this->assertSame('/h/.pablo/state', PabloPaths::pabloPath('state', 'PABLO_TEST_DIR'));
        $this->assertSame('/h/.pablo', PabloPaths::pabloPath(''));

        $this->setEnv('PABLO_TEST_DIR', '');
        $this->assertSame('/h/.pablo/state', PabloPaths::pabloPath('state', 'PABLO_TEST_DIR'), 'an empty override is unset');

        $this->setEnv('PABLO_TEST_DIR', '/elsewhere/');
        $this->assertSame('/elsewhere/', PabloPaths::pabloPath('state', 'PABLO_TEST_DIR'));
    }

    public function testHomeIsReadOnEveryCall(): void
    {
        $this->setEnv('HOME', '/h');
        $this->assertSame('/h', PabloPaths::home());
        $this->setEnv('HOME', '/other');
        $this->assertSame('/other', PabloPaths::home());
    }

    #[DataProvider('unsetHomes')]
    public function testHomeUnsetOrEmptyIsAClearError(?string $home): void
    {
        $this->setEnv('HOME', $home);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('HOME is not set');
        PabloPaths::home();
    }

    #[DataProvider('unsetHomes')]
    public function testPabloDirNeverFallsBackToARelativeTilde(?string $home): void
    {
        $this->setEnv('HOME', $home);

        $this->expectException(PabloError::class);
        PabloPaths::pabloDir();
    }

    /** @return iterable<string, array{?string}> */
    public static function unsetHomes(): iterable
    {
        yield 'unset' => [null];
        yield 'empty' => [''];
    }

    public function testExpandTilde(): void
    {
        $this->setEnv('HOME', '/h');
        $this->assertSame('/h', PabloPaths::expandTilde('~'));
        $this->assertSame('/h/', PabloPaths::expandTilde('~/'));
        $this->assertSame('/h/dev/repo', PabloPaths::expandTilde('~/dev/repo'));
        $this->assertSame('/abs/~/x', PabloPaths::expandTilde('/abs/~/x'));
        $this->assertSame('relative/path', PabloPaths::expandTilde('relative/path'));
        $this->assertSame('~user/dev', PabloPaths::expandTilde('~user/dev'), '~user/ is left to the shell');
        $this->assertSame('~foo', PabloPaths::expandTilde('~foo'));
        $this->assertSame('', PabloPaths::expandTilde(''));
    }

    public function testExpandTildeWithoutHomeOnlyFailsWhenHomeIsNeeded(): void
    {
        $this->setEnv('HOME', null);
        $this->assertSame('/abs/path', PabloPaths::expandTilde('/abs/path'));
        $this->assertSame('~user/dev', PabloPaths::expandTilde('~user/dev'));

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('HOME is not set');
        PabloPaths::expandTilde('~/dev/repo');
    }

    public function testContractTilde(): void
    {
        $this->setEnv('HOME', '/home/me');
        $this->assertSame('~/dev/repo', PabloPaths::contractTilde('/home/me/dev/repo'));
        $this->assertSame('~', PabloPaths::contractTilde('/home/me'));
        $this->assertSame('/home/meadow/repo', PabloPaths::contractTilde('/home/meadow/repo'), 'only on a directory boundary');
        $this->assertSame('/srv/repo', PabloPaths::contractTilde('/srv/repo'));

        $this->setEnv('HOME', '/home/me/');
        $this->assertSame('~/dev', PabloPaths::contractTilde('/home/me/dev'));

        $this->setEnv('HOME', null);
        $this->assertSame('/home/me/dev', PabloPaths::contractTilde('/home/me/dev'), 'HOME unset: as-is');
        $this->setEnv('HOME', '/');
        $this->assertSame('/etc', PabloPaths::contractTilde('/etc'), 'HOME=/: as-is');
    }

    public function testResourcesAreTheRepositoryRootOutsideThePhar(): void
    {
        $this->assertSame(\dirname(__DIR__, 3), PabloPaths::resourcesDir());
        $this->assertDirectoryExists(PabloPaths::resourcesDir().'/opencode/agents');
        $this->assertFileExists(PabloPaths::resourcesDir().'/examples/config.yaml');
        $this->assertSame('', PabloPaths::pharPath());
    }
}
