<?php

declare(strict_types=1);

namespace Pablo\Twig\Components;

use Pablo\Domain\State;
use Pablo\Support\PabloError;
use Pablo\Task\TaskStateChanger;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveListener;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\ComponentToolsTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The dashboard's per-task write exception: the "Force state" and "Skip CI"
 * dialogs, running the same TaskStateChanger as `pablo task:state` and
 * `pablo task:skip-ci`.
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

    #[LiveProp]
    public ?string $message = null;

    #[LiveProp]
    public ?string $error = null;

    public function __construct(
        private readonly TaskStateChanger $changer,
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
        $this->mode = self::MODE_SKIP_CI === $mode ? self::MODE_SKIP_CI : self::MODE_FORCE_STATE;
        $this->project = $project;
        $this->branch = $branch;
        $this->currentState = $state;
        $this->target = '';
        $this->trigger = true;
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
            if (self::MODE_SKIP_CI === $this->mode) {
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
        } catch (PabloError $e) {
            $this->error = $e->getMessage();

            return;
        }

        $this->open = false;
        $this->emit(TaskBoard::TASKS_CHANGED_EVENT);
    }
}
