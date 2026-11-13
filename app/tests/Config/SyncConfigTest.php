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
            'p.yaml',
        );

        $this->assertSame('merge', $sync->strategy);
        $this->assertTrue($sync->autoApply);
        $this->assertSame(15, $sync->intervalMinutes);
    }

    public function testUnknownStrategyNamesTheFile(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("p.yaml: sync.strategy must be 'rebase' or 'merge', got 'squash'");

        SyncConfig::fromYaml(['sync' => ['strategy' => 'squash', 'auto_apply' => false, 'interval_minutes' => 5]], [], 'p.yaml');
    }

    public function testMissingKeyNamesIt(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no value for sync.interval_minutes');

        SyncConfig::fromYaml([], [], 'p.yaml');
    }
}
