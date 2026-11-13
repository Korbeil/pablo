<?php

declare(strict_types=1);

namespace Pablo\Tests\Config;

use Pablo\Config\HooksConfig;
use Pablo\Domain\State;
use PHPUnit\Framework\TestCase;

final class HooksConfigTest extends TestCase
{
    public function testProjectNullTurnsTheGlobalCommandOff(): void
    {
        $hooks = HooksConfig::fromYaml(
            ['hooks' => ['command' => null]],
            ['hooks' => ['command' => 'notify', 'states' => ['closed'], 'agent_finished' => false]],
        );

        $this->assertNull($hooks->command);
        $this->assertSame([State::Closed], $hooks->states);
        $this->assertFalse($hooks->agentFinished);
    }

    public function testNothingSetMeansDefaults(): void
    {
        $hooks = HooksConfig::fromYaml([], []);

        $this->assertNull($hooks->command);
        $this->assertNull($hooks->states);
        $this->assertTrue($hooks->agentFinished);
    }
}
