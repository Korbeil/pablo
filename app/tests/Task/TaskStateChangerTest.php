<?php

declare(strict_types=1);

namespace Pablo\Tests\Task;

use Pablo\Domain\State;
use Pablo\Support\PabloError;
use Pablo\Tests\Command\CommandTestBed;

/**
 * The shared core of `task:state`, `task:skip-ci` and the dashboard's
 * task-actions dialog: addressed by project + branch, never by cwd.
 */
final class TaskStateChangerTest extends CommandTestBed
{
    public function testForceStateRunsTriggers(): void
    {
        chdir($this->tmp);
        $task = $this->stateChanger()->forceState('wallet-kit', 'wk-45', State::RequestChanges);

        $this->assertSame(State::RequestChanges, $task->state);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
        $this->assertSame(['pr-feedback'], $this->agents->launch);
        $this->assertSame([7], $this->gh->drafts);
    }

    public function testForceStateWithoutTriggerOnlyRecords(): void
    {
        $this->stateChanger()->forceState('wallet-kit', 'wk-45', State::RequestChanges, trigger: false);

        $this->assertSame(State::RequestChanges, $this->getTask()->state);
        $this->assertSame([], $this->agents->launch);
        $this->assertSame([], $this->gh->drafts);
    }

    public function testForceStateKeepsEngineRules(): void
    {
        $task = $this->getTask();
        $task->state = State::RequestChanges;
        $this->store->save($task);

        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('waiting is impossible — address the feedback and use /pablo-commit-and-pr instead');
        $this->stateChanger()->forceState('wallet-kit', 'wk-45', State::Waiting);
    }

    public function testUnknownTaskFails(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('no task nope');
        $this->stateChanger()->forceState('wallet-kit', 'nope', State::Draft);
    }

    public function testSkipCiFromCiRed(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);

        $this->stateChanger()->skipCi('wallet-kit', 'wk-45');

        $updated = $this->getTask();
        $this->assertTrue($updated->ciIgnored);
        $this->assertSame(State::WaitingReview, $updated->state);
        $this->assertSame([7], $this->gh->readies);
    }

    public function testSkipCiRefusedOutsideCiRed(): void
    {
        $this->expectException(PabloError::class);
        $this->expectExceptionMessage('not ci-red');
        try {
            $this->stateChanger()->skipCi('wallet-kit', 'wk-45');
        } finally {
            $this->assertFalse($this->getTask()->ciIgnored);
            $this->assertSame(State::InProgress, $this->getTask()->state);
        }
    }
}
