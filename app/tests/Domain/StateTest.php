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
        $sm = new StateMachine(
            new \Pablo\Tests\FakeGhPr(),
            new class implements \Pablo\Provider\Tracker\ProviderRegistryInterface {
                public function get(string $name): \Pablo\Provider\Tracker\Provider
                {
                    throw new \LogicException('not used');
                }
            },
            new \Pablo\Support\RepoSlug(new \Pablo\Tests\FakeGit()),
            new \Pablo\Domain\Time(),
        );
        $table = array_keys($sm->states());

        sort($states);
        sort($table);

        $this->assertSame(
            $states,
            $table,
            'State::all() and the StateMachine states() table must stay in sync',
        );
    }
}
