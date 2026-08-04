<?php

declare(strict_types=1);

namespace Pablo\Tests\Domain;

use Pablo\Domain\State;
use Pablo\StateMachine\StateMachine;
use PHPUnit\Framework\TestCase;

final class StateTest extends TestCase
{
    public function testAllStatesMatchStateMachineTable(): void
    {
        $states = array_map(static fn (State $s) => $s->value, State::all());
        $table = array_keys(StateMachine::states());

        sort($states);
        sort($table);

        $this->assertSame(
            $states,
            $table,
            'State::all() and the StateMachine states() table must stay in sync',
        );
    }
}
