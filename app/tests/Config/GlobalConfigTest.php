<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\TestCase;

final class GlobalConfigTest extends TestCase
{
    use UsesGlobalConfig;

    protected function tearDown(): void
    {
        $this->unsetGlobalConfig();
    }

    public function testDashboardPortDefaultsWhenUnset(): void
    {
        $this->writeGlobalConfig("default_model: x\n");

        $this->assertSame(8321, (new GlobalConfig())->dashboardPort(8321));
    }

    public function testDashboardPortFromTheConfig(): void
    {
        $this->writeGlobalConfig("dashboard_port: 8400\n");

        $this->assertSame(8400, (new GlobalConfig())->dashboardPort(8321));
    }

    public function testAnInvalidDashboardPortIsAClearError(): void
    {
        $this->writeGlobalConfig("dashboard_port: 99999\n");

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('dashboard_port must be a port number');
        (new GlobalConfig())->dashboardPort(8321);
    }

    public function testIsPort(): void
    {
        foreach ([1, 8321, 65535, '8400'] as $port) {
            $this->assertTrue(GlobalConfig::isPort($port), (string) $port);
        }
        foreach ([0, 65536, -1, '80a', '', null, 8321.5] as $port) {
            $this->assertFalse(GlobalConfig::isPort($port), var_export($port, true));
        }
    }
}
