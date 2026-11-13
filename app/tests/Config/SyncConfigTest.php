<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\SyncConfig;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class SyncConfigTest extends TestCase
{
    public function testProjectValueWinsOverEachDefault(): void
    {
        $sync = SyncConfig::fromYaml(
            ['sync' => ['strategy' => 'merge']],
            ['sync' => ['strategy' => 'rebase', 'auto_apply' => true, 'interval_minutes' => 15]],
        );

        $this->assertSame('merge', $sync->strategy);
        $this->assertTrue($sync->autoApply);
        $this->assertSame(15, $sync->intervalMinutes);
    }

    public function testANullStrategyIsUnset(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no value for sync.strategy');

        SyncConfig::fromYaml(['sync' => ['strategy' => null, 'auto_apply' => false, 'interval_minutes' => 5]], []);
    }

    public function testMissingKeyNamesIt(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no value for sync.interval_minutes');

        SyncConfig::fromYaml([], []);
    }
}
