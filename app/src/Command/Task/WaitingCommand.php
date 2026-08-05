<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Domain\State;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class WaitingCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('task:waiting')->setDescription('toggle the waiting pause for the current task');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $ctx = $this->resolveCtx($store, $this->agents());
        $lock = Store::taskLock($store, $ctx->task->project, $ctx->task->branch);
        try {
            $endedIn = StateMachine::toggleWaiting($ctx);
        } finally {
            $lock->release();
        }
        if (State::Waiting === $endedIn) {
            $output->writeln("{$ctx->task->branch}: paused (waiting); will restore to {$ctx->task->stateBeforeWaiting?->value}");
        } else {
            $output->writeln("{$ctx->task->branch}: un-paused, back to {$endedIn->value}");
        }

        return self::SUCCESS;
    }
}
