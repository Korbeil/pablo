<?php

declare(strict_types=1);

namespace Pablo\Tests\Support;

use Pablo\Support\PabloError;
use Pablo\Support\PabloPaths;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testHomeIsReadOnEveryCall(): void
    {
        putenv('HOME=/h');
        $this->assertSame('/h', PabloPaths::home());
        putenv('HOME=/other');
        $this->assertSame('/other', PabloPaths::home());
    }

    #[DataProvider('unsetHomes')]
    public function testHomeUnsetOrEmptyIsAClearError(string $assignment): void
    {
        putenv($assignment);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('HOME is not set');
        PabloPaths::home();
    }

    #[DataProvider('unsetHomes')]
    public function testPabloDirNeverFallsBackToARelativeTilde(string $assignment): void
    {
        putenv($assignment);

        $this->expectException(PabloError::class);
        PabloPaths::pabloDir();
    }

    /** @return iterable<string, array{string}> */
    public static function unsetHomes(): iterable
    {
        yield 'unset' => ['HOME'];
        yield 'empty' => ['HOME='];
    }

    public function testExpandTilde(): void
    {
        putenv('HOME=/h');
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
        putenv('HOME');
        $this->assertSame('/abs/path', PabloPaths::expandTilde('/abs/path'));
        $this->assertSame('~user/dev', PabloPaths::expandTilde('~user/dev'));

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('HOME is not set');
        PabloPaths::expandTilde('~/dev/repo');
    }

    public function testContractTilde(): void
    {
        putenv('HOME=/home/me');
        $this->assertSame('~/dev/repo', PabloPaths::contractTilde('/home/me/dev/repo'));
        $this->assertSame('~', PabloPaths::contractTilde('/home/me'));
        $this->assertSame('/home/meadow/repo', PabloPaths::contractTilde('/home/meadow/repo'), 'only on a directory boundary');
        $this->assertSame('/srv/repo', PabloPaths::contractTilde('/srv/repo'));

        putenv('HOME=/home/me/');
        $this->assertSame('~/dev', PabloPaths::contractTilde('/home/me/dev'));

        putenv('HOME');
        $this->assertSame('/home/me/dev', PabloPaths::contractTilde('/home/me/dev'), 'HOME unset: as-is');
        putenv('HOME=/');
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
