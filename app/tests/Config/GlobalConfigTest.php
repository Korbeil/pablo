<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;
use Pablo\Tests\UsesGlobalConfig;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testNoDashboardTrustedHostsByDefault(): void
    {
        $this->writeGlobalConfig("default_model: x\n");

        $this->assertSame([], (new GlobalConfig())->dashboardTrustedHosts());
    }

    public function testDashboardTrustedHostsAreNormalizedAndDeduplicated(): void
    {
        $this->writeGlobalConfig(<<<'YAML'
            dashboard_trusted_hosts:
              - Pablo.Home.Arpa
              - pablo.home.arpa
              - 192.168.1.20
              - "::1"
              - "[fd00::2]"
            YAML);

        $this->assertSame(
            ['pablo.home.arpa', '192.168.1.20', '[::1]', '[fd00::2]'],
            (new GlobalConfig())->dashboardTrustedHosts(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidTrustedHosts(): iterable
    {
        yield 'not a list' => ['dashboard_trusted_hosts: pablo.home.arpa'];
        yield 'a mapping' => ['dashboard_trusted_hosts: {a: pablo.home.arpa}'];
        yield 'with a port' => ['dashboard_trusted_hosts: ["pablo.home.arpa:8321"]'];
        yield 'with a scheme' => ['dashboard_trusted_hosts: ["http://pablo.home.arpa"]'];
        yield 'a wildcard' => ['dashboard_trusted_hosts: ["*.home.arpa"]'];
        yield 'a regex' => ['dashboard_trusted_hosts: [".*"]'];
        yield 'not a string' => ['dashboard_trusted_hosts: [8321]'];
    }

    #[DataProvider('invalidTrustedHosts')]
    public function testInvalidDashboardTrustedHostsAreAClearError(string $yaml): void
    {
        $this->writeGlobalConfig($yaml."\n");

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('dashboard_trusted_hosts');
        (new GlobalConfig())->dashboardTrustedHosts();
    }

    public function testSchedulerPathIsEmptyWhenUnset(): void
    {
        $this->writeGlobalConfig("default_model: x\n");

        $this->assertSame([], (new GlobalConfig())->schedulerPath());
    }

    public function testSchedulerPathAsAListOrAColonSeparatedString(): void
    {
        $this->writeGlobalConfig("scheduler:\n  path: ['~/tools/bin', /opt/x/bin]\n");
        $this->assertSame(['~/tools/bin', '/opt/x/bin'], (new GlobalConfig())->schedulerPath());

        $this->writeGlobalConfig("scheduler:\n  path: '/a/bin::/b/bin'\n");
        $this->assertSame(['/a/bin', '/b/bin'], (new GlobalConfig())->schedulerPath());
    }

    public function testAnInvalidSchedulerPathIsAClearError(): void
    {
        $this->writeGlobalConfig("scheduler:\n  path: [{a: b}]\n");

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('scheduler.path must be a list of directories');
        (new GlobalConfig())->schedulerPath();
    }

    public function testPruneKeepDays(): void
    {
        $this->writeGlobalConfig("default_model: x\n");
        $this->assertSame(10, (new GlobalConfig())->pruneKeepDays(10));

        $this->writeGlobalConfig("prune:\n  keep_days: 45\n");
        $this->assertSame(45, (new GlobalConfig())->pruneKeepDays(10));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidPruneKeepDays(): iterable
    {
        yield 'zero' => ["prune:\n  keep_days: 0\n"];
        yield 'negative' => ["prune:\n  keep_days: -3\n"];
        yield 'not a number' => ["prune:\n  keep_days: soon\n"];
        yield 'not a mapping' => ["prune: 10\n"];
    }

    #[DataProvider('invalidPruneKeepDays')]
    public function testAnInvalidPruneKeepDaysIsAClearError(string $yaml): void
    {
        $this->writeGlobalConfig($yaml);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('config.yaml: prune');
        (new GlobalConfig())->pruneKeepDays(10);
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
