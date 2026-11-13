<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents;

use Pablo\Agents\Ade\OpenChamber\OpenChamberLauncher;
use Pablo\Agents\Ade\Orca\OrcaLauncher;
use Pablo\Support\PabloError;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

final class AgentLauncherTest extends TestCase
{
    use UsesGlobalConfig;

    protected function tearDown(): void
    {
        $this->unsetGlobalConfig();
        putenv('PABLO_AGENT_BACKEND');
    }

    public function testDefaultBackendIsOrca(): void
    {
        putenv('PABLO_AGENT_BACKEND');
        $this->writeGlobalConfig("sync:\n  strategy: rebase\n");
        $this->assertInstanceOf(OrcaLauncher::class, \Pablo\Tests\Registries::launchers(new \Pablo\Tests\FakeProcessRunner(), new \Pablo\Config\GlobalConfig())->create());
    }

    public function testEnvVarSelectsOpenChamber(): void
    {
        putenv('PABLO_AGENT_BACKEND=openchamber');
        $this->assertInstanceOf(OpenChamberLauncher::class, \Pablo\Tests\Registries::launchers(new \Pablo\Tests\FakeProcessRunner(), new \Pablo\Config\GlobalConfig())->create());
    }

    public function testGlobalConfigSelectsOpenChamber(): void
    {
        putenv('PABLO_AGENT_BACKEND');
        $this->writeGlobalConfig("agent_backend: openchamber\n");
        $this->assertInstanceOf(OpenChamberLauncher::class, \Pablo\Tests\Registries::launchers(new \Pablo\Tests\FakeProcessRunner(), new \Pablo\Config\GlobalConfig())->create());
    }

    public function testExplicitArgumentBeatsEnv(): void
    {
        putenv('PABLO_AGENT_BACKEND=openchamber');
        $this->assertInstanceOf(OrcaLauncher::class, \Pablo\Tests\Registries::launchers(new \Pablo\Tests\FakeProcessRunner(), new \Pablo\Config\GlobalConfig())->create('orca'));
    }

    public function testUnknownBackendThrows(): void
    {
        putenv('PABLO_AGENT_BACKEND');
        $this->expectException(PabloError::class);
        \Pablo\Tests\Registries::launchers(new \Pablo\Tests\FakeProcessRunner(), new \Pablo\Config\GlobalConfig())->create('nope');
    }
}
