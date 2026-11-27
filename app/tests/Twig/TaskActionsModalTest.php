<?php

declare(strict_types=1);

namespace Pablo\Tests\Twig;

use Pablo\Domain\State;
use Pablo\Task\TaskCloser;
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

    /** Like `pablo task:close`, the dashboard closes a task whatever its state, not only a closed one. */
    public function testCloseTaskWorksOutsideClosedState(): void
    {
        $this->assertNotSame(State::Closed, $this->getTask()->state);
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $modal->confirm();

        $this->assertFalse($modal->open);
        $this->assertNull($modal->error);
        $this->assertSame([[$this->wt, 'wk-45']], $this->git->removed);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
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

    public function testCloseTaskListsUncommittedFilesAndNeedsTheirApproval(): void
    {
        $this->git->changes = [' M src/a.php', '?? notes.txt'];
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $this->assertSame([' M src/a.php', '?? notes.txt'], $modal->changes);
        $this->assertFalse($modal->discard);

        $modal->confirm();
        $this->assertTrue($modal->open);
        $this->assertStringContainsString('Tick the box', (string) $modal->error);
        $this->assertSame([], $this->git->removed);

        $modal->discard = true;
        $modal->confirm();
        $this->assertFalse($modal->open);
        $this->assertSame([$this->wt], $this->git->discarded);
        $this->assertStringContainsString('2 uncommitted file(s) deleted', (string) $modal->message);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    /** A file that appeared after the approval is never deleted unseen: the dialog shows the new list and asks again. */
    public function testCloseTaskAsksAgainWhenTheFilesChangedSinceTheApproval(): void
    {
        $this->git->changes = ['?? notes.txt'];
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $modal->discard = true;
        $this->git->changes = ['?? notes.txt', '?? secret-draft.md'];
        $modal->confirm();

        $this->assertTrue($modal->open);
        $this->assertFalse($modal->discard);
        $this->assertSame(['?? notes.txt', '?? secret-draft.md'], $modal->changes);
        $this->assertStringContainsString('changed since', (string) $modal->error);
        $this->assertSame([], $this->git->removed);

        $modal->discard = true;
        $modal->confirm();
        $this->assertFalse($modal->open);
        $this->assertNull($this->store->get('wallet-kit', 'wk-45'));
    }

    /** A huge list only travels as a preview, a count and a fingerprint, and still closes once approved. */
    public function testCloseTaskKeepsOnlyAPreviewOfAHugeList(): void
    {
        $files = array_map(static fn (int $i): string => "?? build/{$i}.js", range(1, 500));
        $this->git->changes = $files;
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);

        $this->assertCount(TaskActionsModal::CHANGES_PREVIEW, $modal->changes);
        $this->assertSame(500, $modal->changeCount);
        $this->assertSame(TaskCloser::fingerprint($files), $modal->changesFingerprint);

        $modal->discard = true;
        $modal->confirm();
        $this->assertFalse($modal->open);
        $this->assertStringContainsString('500 uncommitted file(s) deleted', (string) $modal->message);
    }

    /** Files appearing in a worktree that was clean when the dialog opened stop the close too. */
    public function testCloseTaskOfACleanWorktreeStopsOnNewFiles(): void
    {
        $modal = $this->modal(TaskActionsModal::MODE_CLOSE_TASK);
        $this->assertSame([], $modal->changes);
        $this->git->changes = ['?? late.txt'];
        $modal->confirm();

        $this->assertTrue($modal->open);
        $this->assertSame(['?? late.txt'], $modal->changes);
        $this->assertSame([], $this->git->removed);
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
