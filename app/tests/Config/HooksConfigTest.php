<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\HooksConfig;
use Pablo\Domain\State;
use Pablo\Support\PabloError;
use PHPUnit\Framework\TestCase;

final class HooksConfigTest extends TestCase
{
    public function testProjectNullTurnsTheGlobalCommandOff(): void
    {
        $hooks = HooksConfig::fromYaml(
            ['hooks' => ['command' => null]],
            ['hooks' => ['command' => 'notify', 'states' => ['closed'], 'agent_finished' => false]],
            'p.yaml',
        );

        $this->assertNull($hooks->command);
        $this->assertSame([State::Closed], $hooks->states);
        $this->assertFalse($hooks->agentFinished);
    }

    public function testNothingSetMeansDefaults(): void
    {
        $hooks = HooksConfig::fromYaml([], [], 'p.yaml');

        $this->assertNull($hooks->command);
        $this->assertNull($hooks->states);
        $this->assertTrue($hooks->agentFinished);
    }

    public function testUnknownStateNamesTheFile(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage("config.yaml: hooks.states has unknown state 'done'");

        HooksConfig::fromYaml([], ['hooks' => ['states' => ['done']]], 'config.yaml');
    }
}
