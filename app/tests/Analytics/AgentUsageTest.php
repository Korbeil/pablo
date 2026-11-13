<?php

declare(strict_types=1);

namespace Pablo\Tests\Analytics;

use Pablo\Analytics\AgentUsage;
use Pablo\Support\ProbeResult;
use Pablo\Tests\FakeProcessRunner;
use PHPUnit\Framework\TestCase;

/**
 * The selected ADE's own usage is asked first; the runtime's storage only
 * when the ADE keeps none (or has nothing for the run).
 */
final class AgentUsageTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PABLO_AGENT_BACKEND');
        putenv('PABLO_AGENT_RUNTIME');
    }

    /** @return list<string> the CLI of every probe the harvest made */
    private static function probedClis(string $backend): array
    {
        putenv('PABLO_AGENT_BACKEND='.$backend);
        putenv('PABLO_AGENT_RUNTIME=opencode');
        $runner = new FakeProcessRunner();
        $runner->onProbe = static fn (array $argv): ProbeResult => new ProbeResult(1, 'nothing');

        (new AgentUsage($runner, \Pablo\Tests\Registries::runtimes(), \Pablo\Tests\Registries::ades()))->harvest('/wt', '2026-08-20T10:00:00+00:00', null);

        return array_values(array_unique(array_map(static fn (array $argv): string => $argv[0], $runner->probeCalls)));
    }

    public function testAnAdeKeepingUsageIsAskedBeforeTheRuntime(): void
    {
        $this->assertSame(['openchamber', 'opencode'], self::probedClis('openchamber'));
    }

    public function testOtherwiseOnlyTheRuntimeIsAsked(): void
    {
        $this->assertSame(['opencode'], self::probedClis('orca'));
        $this->assertSame(['opencode'], self::probedClis('headless'));
    }
}
