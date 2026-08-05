<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Domain\State;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SkipCiCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('task:skip-ci')->setDescription('ignore failing CI checks and move the current task past ci-red');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $ctx = $this->resolveCtx($store, $this->agents());
        if (State::CiRed !== $ctx->task->state) {
            throw new PabloError("task is {$ctx->task->state->value}, not ci-red — nothing to skip");
        }
        $lock = Store::taskLock($store, $ctx->task->project, $ctx->task->branch);
        try {
            $ctx->task->ciIgnored = true;
            StateMachine::enterState($ctx, State::ReadyToReview);
        } finally {
            $lock->release();
        }
        $output->writeln("{$ctx->task->branch}: CI results skipped, moved to {$ctx->task->state->value}");

        return self::SUCCESS;
    }
}
