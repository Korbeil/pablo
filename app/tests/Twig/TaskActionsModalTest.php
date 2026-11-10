<?php

declare(strict_types=1);

namespace Pablo\Tests\Twig;

use Pablo\Domain\State;
use Pablo\Tests\Command\CommandTestBed;
use Pablo\Twig\Components\TaskActionsModal;
use Pablo\Twig\Components\TaskBoard;
use Symfony\UX\LiveComponent\LiveResponder;

/**
 * The Force state / Skip CI dialog: nothing changes until confirm(), errors
 * keep the dialog open, success closes it.
 */
final class TaskActionsModalTest extends CommandTestBed
{
    private LiveResponder $responder;

    private function modal(string $mode): TaskActionsModal
    {
        $modal = new TaskActionsModal($this->stateChanger(), $this->taskCloser());
        $modal->setLiveResponder($this->responder = new LiveResponder());
        $modal->openFor($mode, 'wallet-kit', 'wk-45', $this->getTask()->state->value);

        return $modal;
    }

    public function testOpeningChangesNothing(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_FORCE_STATE);

        $this->assertTrue($modal->open);
        $this->assertTrue($modal->trigger);
        $this->assertSame('in-progress', $modal->currentState);
        $this->assertSame(State::InProgress, $this->getTask()->state);
    }

    public function testForceStateRequiresATarget(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_FORCE_STATE);
        $modal->confirm();

        $this->assertTrue($modal->open);
        $this->assertStringContainsString('Pick the state', (string) $modal->error);
        $this->assertSame(State::InProgress, $this->getTask()->state);
    }

    public function testForceStateWithoutTriggers(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_FORCE_STATE);
        $modal->target = 'request-changes';
        $modal->trigger = false;
        $modal->confirm();

        $this->assertFalse($modal->open);
        $this->assertNull($modal->error);
        $this->assertStringContainsString('triggers skipped', (string) $modal->message);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
        $this->assertSame([], $this->agents->launch);
    }

    public function testForceStateWithTriggers(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_FORCE_STATE);
        $modal->target = 'request-changes';
        $modal->confirm();

        $this->assertFalse($modal->open);
        $this->assertSame(State::RequestChanges, $this->getTask()->state);
        $this->assertSame(['pr-feedback'], $this->agents->launch);
        $this->assertSame(TaskBoard::TASKS_CHANGED_EVENT, $this->responder->getEventsToEmit()[0]['event'] ?? null);
    }

    public function testSkipCiErrorKeepsDialogOpen(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_SKIP_CI);
        $modal->confirm();

        $this->assertTrue($modal->open);
        $this->assertStringContainsString('not ci-red', (string) $modal->error);
        $this->assertSame([], $this->responder->getEventsToEmit());
    }

    public function testSkipCiFromCiRed(): void
    {
        $task = $this->getTask();
        $task->state = State::CiRed;
        $this->store->save($task);

        $modal = $this->modal(TaskActionsModal::MODE_SKIP_CI);
        $modal->confirm();

        $this->assertFalse($modal->open);
        $this->assertStringContainsString('CI results skipped', (string) $modal->message);
        $this->assertTrue($this->getTask()->ciIgnored);
    }

    private function parkInClosed(): void
    {
        $task = $this->getTask();
        $task->state = State::Closed;
        $this->store->save($task);
    }

    public function testCloseTaskRefusedOutsideClosedState(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $modal->confirm();

        $this->assertTrue($modal->open);
        $this->assertStringContainsString('not closed', (string) $modal->error);
        $this->assertNotNull($this->store->get('wallet-kit', 'wk-45'));
        $this->assertSame([], $this->git->removed);
    }

    public function testCloseTaskRemovesEverything(): void
    {
        $this->parkInClosed();
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $this->assertNotNull($this->store->get('wallet-kit', 'wk-45'));
        $modal->confirm();

        $this->assertFalse($modal->open);
        $this->assertNull($modal->error);
        $this->assertStringContainsString('task closed', (string) $modal->message);
        $this->assertSame([[$this->wt, 'wk-45']], $this->git->removed);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
        $this->assertCount(1, $this->analytics->closed);
        $this->assertSame(TaskBoard::TASKS_CHANGED_EVENT, $this->responder->getEventsToEmit()[0]['event'] ?? null);
    }

    public function testCloseTaskRefusedWhileAgentsRunUnlessForced(): void
    {
        $this->parkInClosed();
        $this->agents->active = [new \Pablo\Agents\SessionInfo('a', 'running')];
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $modal->confirm();

        $this->assertTrue($modal->open);
        $this->assertStringContainsString('still active', (string) $modal->error);
        $this->assertNotNull($this->store->get('wallet-kit', 'wk-45'));
        $this->assertSame([], $this->git->removed);

        $modal->force = true;
        $modal->confirm();
        $this->assertFalse($modal->open);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }
}
