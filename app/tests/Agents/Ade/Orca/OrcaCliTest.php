<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Ade\Orca;

use Pablo\Agents\Ade\Orca\OrcaCli;
use Pablo\Agents\Ade\Orca\OrcaLauncher;
use Pablo\Domain\Time;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * The Orca app's Linux CLI is `orca-ide`: bare `orca` there is GNOME's
 * screen reader, which PABLO must never call.
 */
final class OrcaCliTest extends TestCase
{
    private string|false $env;

    protected function setUp(): void
    {
        $this->env = getenv(OrcaCli::ENV);
        putenv(OrcaCli::ENV); // phpunit.xml pins it for every other test
    }

    protected function tearDown(): void
    {
        putenv(false === $this->env ? OrcaCli::ENV : OrcaCli::ENV.'='.$this->env);
    }

    public function testOrcaIdeOnLinuxOrcaElsewhere(): void
    {
        $this->assertSame('orca-ide', OrcaCli::command('Linux'));
        $this->assertSame('orca', OrcaCli::command('Darwin'));
        $this->assertSame('orca', OrcaCli::command('Windows'));
    }

    public function testOrcasOwnOverrideWins(): void
    {
        putenv(OrcaCli::ENV.'=/opt/Orca/resources/bin/orca-ide');
        $this->assertSame('/opt/Orca/resources/bin/orca-ide', OrcaCli::command('Linux'));
    }

    public function testAdoptionTimeoutKeepsOrcasLastReply(): void
    {
        $runner = new FakeProcessRunner();
        $runner->onRun = static fn (): string => (string) json_encode(['ok' => false, 'error' => ['code' => 'selector_not_found']]);
        $launcher = new OrcaLauncher($runner, new Time(), \Pablo\Tests\Registries::defaultRuntime());

        $reason = (new \ReflectionMethod($launcher, 'waitForOrcaAdoption'))->invoke($launcher, '/tmp/wt', 1);

        $this->assertIsString($reason);
        $this->assertStringContainsString('orca adoption timeout for /tmp/wt', $reason);
        $this->assertStringContainsString('selector_not_found', $reason);
        $this->assertSame(OrcaCli::command(), $runner->calls[0][0][0]);
    }
}
