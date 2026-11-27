<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Domain\State;
use Pablo\Support\PabloError;
use Pablo\Task\TaskCloser;
use Pablo\Task\TaskStateChanger;
use Pablo\Task\UncommittedChanges;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The dashboard's per-task write exception: the "Force state", "Skip CI" and
 * "Close the task" dialogs, running the same TaskStateChanger / TaskCloser as
 * `pablo task:state`, `pablo task:skip-ci` and `pablo task:close`.
 *
 * Lives outside the TaskBoard so the board's 30-second poll never wipes an
 * open dialog: a row button emits OPEN_EVENT with the task's coordinates,
 * and nothing changes until the dialog's Confirm button is clicked. On
 * success TaskBoard::TASKS_CHANGED_EVENT asks the board to re-render now.
 */
#[AsLiveComponent]
final class TaskActionsModal
{
    use ComponentToolsTrait;
    use DefaultActionTrait;

    public const OPEN_EVENT = 'pablo:task-actions';

    public const MODE_FORCE_STATE = 'force-state';
    public const MODE_SKIP_CI = 'skip-ci';
    public const MODE_CLOSE_TASK = 'close-task';

    private const MODES = [self::MODE_FORCE_STATE, self::MODE_SKIP_CI, self::MODE_CLOSE_TASK];

    #[LiveProp]
    public bool $open = false;

    #[LiveProp]
    public string $mode = self::MODE_FORCE_STATE;

    #[LiveProp]
    public string $project = '';

    #[LiveProp]
    public string $branch = '';

    #[LiveProp]
    public string $currentState = '';

    #[LiveProp(writable: true)]
    public string $target = '';

    #[LiveProp(writable: true)]
    public bool $trigger = true;

    /** Close the task even while agent sessions are active. */
    #[LiveProp(writable: true)]
    public bool $force = false;

    /** How many uncommitted files the dialog lists at most (a worktree may hold thousands). */
    public const CHANGES_PREVIEW = 20;

    /**
     * Close the task: the first CHANGES_PREVIEW of the worktree's uncommitted
     * files, read when the dialog opens (and again when they changed before
     * Confirm). Only their count and fingerprint travel with the rest.
     *
     * @var list<string>
     */
    #[LiveProp]
    public array $changes = [];

    #[LiveProp]
    public int $changeCount = 0;

    /** TaskCloser::fingerprint() of the whole list: what Confirm approves. */
    #[LiveProp]
    public ?string $changesFingerprint = null;

    /** Close the task: the user approved deleting the uncommitted files. */
    #[LiveProp(writable: true)]
    public bool $discard = false;

    #[LiveProp]
    public ?string $message = null;

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly TaskStateChanger $changer,
        private readonly TaskCloser $closer,
    ) {
    }

    /** @return list<State> every state, in the display order the tables use */
    public function states(): array
    {
        return State::DISPLAY_ORDER;
    }

    #[LiveListener(self::OPEN_EVENT)]
    public function openFor(
        #[LiveArg] string $mode,
        #[LiveArg] string $project,
        #[LiveArg] string $branch,
        #[LiveArg] string $state,
    ): void {
        $this->mode = \in_array($mode, self::MODES, true) ? $mode : self::MODE_FORCE_STATE;
        $this->project = $project;
        $this->branch = $branch;
        $this->currentState = $state;
        $this->target = '';
        $this->trigger = true;
        $this->force = false;
        $this->showChanges([]);
        if (self::MODE_CLOSE_TASK === $this->mode) {
            try {
                $this->showChanges($this->closer->uncommittedChanges($project, $branch));
            } catch (PabloError) {
                // No such task any more: Confirm reports it.
            }
        }
        $this->error = null;
        $this->message = null;
        $this->open = true;
    }

    #[LiveAction]
    public function close(): void
    {
        $this->open = false;
        $this->error = null;
    }

    #[LiveAction]
    public function dismiss(): void
    {
        $this->message = null;
    }

    #[LiveAction]
    public function confirm(): void
    {
        $this->error = null;
        $this->message = null;

        try {
            if (self::MODE_CLOSE_TASK === $this->mode) {
                if ($this->changeCount > 0 && !$this->discard) {
                    $this->error = 'Tick the box to delete the uncommitted files with the worktree.';

                    return;
                }
                $discard = $this->discard ? $this->changesFingerprint : null;
                $cleanup = $this->closer->close($this->project, $this->branch, $this->force, $discard);
                $this->message = "{$this->branch}: task closed, {$cleanup}"
                    .(null !== $discard && $this->changeCount > 0 ? ", {$this->changeCount} uncommitted file(s) deleted" : '')
                    .'. The pull request is untouched.';
            } elseif (self::MODE_SKIP_CI === $this->mode) {
                $task = $this->changer->skipCi($this->project, $this->branch);
                $this->message = "{$task->branch}: CI results skipped, moved to {$task->state->value}";
            } else {
                $state = State::tryFrom($this->target);
                if (null === $state) {
                    $this->error = 'Pick the state to move the task to.';

                    return;
                }
                $task = $this->changer->forceState($this->project, $this->branch, $state, $this->trigger);
                $this->message = "{$task->branch}: state set to {$task->state->value}"
                    .($this->trigger ? '' : ' (triggers skipped)');
            }
        } catch (UncommittedChanges $e) {
            // New or different files since the dialog opened: show the
            // current list and ask for the approval again.
            $this->showChanges($e->files);
            $this->error = ucfirst($e->getMessage()).'.';

            return;
        } catch (PabloError $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->open = false;
        $this->emit(TaskBoard::TASKS_CHANGED_EVENT);
    }

    /** @param list<string> $files */
    private function showChanges(array $files): void
    {
        $this->changes = \array_slice($files, 0, self::CHANGES_PREVIEW);
        $this->changeCount = \count($files);
        $this->changesFingerprint = [] === $files ? null : TaskCloser::fingerprint($files);
        $this->discard = false;
    }
}
