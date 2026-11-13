<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade;

use Pablo\Agents\Ade\AdeInterface;
use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Time;
use Pablo\Support\PabloError;
use Pablo\Tests\FakeProcessRunner;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What every registered ADE must honour, so shared code can rely on the
 * interfaces alone.
 */
final class AdeContractTest extends TestCase
{
    use UsesGlobalConfig;

    protected function setUp(): void
    {
        putenv('PABLO_AGENT_BACKEND');
        putenv('PABLO_AGENT_RUNTIME');
        $this->writeGlobalConfig("{}\n");
    }

    protected function tearDown(): void
    {
        $this->unsetGlobalConfig();
    }

    /** @return iterable<string, array{string}> */
    public static function ades(): iterable
    {
        foreach (AdeRegistry::NAMES as $name) {
            yield $name => [$name];
        }
    }

    private function ade(string $name): AdeInterface
    {
        return (new AdeRegistry(new GlobalConfig()))->resolve($name);
    }

    #[DataProvider('ades')]
    public function testIdentityProbeAndLauncher(string $name): void
    {
        $ade = $this->ade($name);
        $this->assertSame($name, $ade->name());
        $this->assertNotSame('', $ade->label());
        $this->assertNotSame('', $ade->followHint());

        $probe = $ade->probe();
        if (null !== $probe) {
            $this->assertSame($probe->cli, $probe->argv[0]);
            $this->assertTrue($probe->soft, 'an ADE CLI only degrades: agents fall back to a headless run');
        }

        $runtime = \Pablo\Tests\Registries::runtimes(new GlobalConfig())->resolve(AgentRuntimeRegistry::DEFAULT);
        $this->assertNull($ade->runtimeError($runtime), 'every ADE runs the default runtime');
        $launcher = $ade->launcher($runtime, new FakeProcessRunner(), new Time());
        $this->assertInstanceOf(AgentLauncherInterface::class, $launcher);
    }

    #[DataProvider('ades')]
    public function testFactoryBuildsTheConfiguredAde(string $name): void
    {
        $this->writeGlobalConfig("agent_backend: {$name}\n");
        $this->assertSame($name, \Pablo\Tests\Registries::ades()->resolve()->name());
        $factory = \Pablo\Tests\Registries::launchers(new FakeProcessRunner(), new GlobalConfig());
        $this->assertInstanceOf(AgentLauncherInterface::class, $factory->create());
    }

    public function testDefaultAndUnknownAde(): void
    {
        $registry = new AdeRegistry(new GlobalConfig());
        $this->assertSame(AdeRegistry::DEFAULT, $registry->resolve()->name());

        $this->writeGlobalConfig("agent_backend: tmux\n");
        $this->assertSame(AdeRegistry::DEFAULT, $registry->resolveOrDefault()->name());
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("unknown agent_backend 'tmux'");
        $registry->resolve();
    }

    public function testEveryRuntimeHasAnAdeAndTheDefaultAdeRunsThemAll(): void
    {
        $default = $this->ade(AdeRegistry::DEFAULT);
        foreach ((\Pablo\Tests\Registries::runtimes(new GlobalConfig()))->all() as $runtime) {
            $this->assertNull($default->runtimeError($runtime), "{$runtime->name()} must run on the default ADE");
        }
    }
}
