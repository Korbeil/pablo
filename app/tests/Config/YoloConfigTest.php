<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\ConfigSchema;
use Pablo\Config\YoloConfig;
use PHPUnit\Framework\TestCase;

final class YoloConfigTest extends TestCase
{
    public function testOffUnlessEnabled(): void
    {
        $yolo = YoloConfig::fromYaml([], []);

        $this->assertFalse($yolo->enabled);
        $this->assertSame(YoloConfig::DEFAULT_MAX_ROUNDS, $yolo->maxRounds);
    }

    public function testTheProjectWinsPerKey(): void
    {
        $yolo = YoloConfig::fromYaml(
            ['yolo' => ['enabled' => false]],
            ['yolo' => ['enabled' => true, 'max_rounds' => 2]],
        );

        $this->assertFalse($yolo->enabled);
        $this->assertSame(2, $yolo->maxRounds);
    }

    public function testTheSchemaChecksBothKeysInBothFiles(): void
    {
        foreach ([ConfigSchema::GLOBAL, ConfigSchema::PROJECT] as $scope) {
            $this->assertNull(ConfigSchema::valueError($scope, 'yolo.enabled', true));
            $this->assertNull(ConfigSchema::valueError($scope, 'yolo.max_rounds', 3));
            $this->assertNotNull(ConfigSchema::valueError($scope, 'yolo.enabled', 'yes'));
            $this->assertNotNull(ConfigSchema::valueError($scope, 'yolo.max_rounds', 0));
        }
    }
}
