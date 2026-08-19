<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Domain\State;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StateCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('task:state')
            ->setDescription('force the current task to a state')
            ->addArgument('state', InputArgument::REQUIRED, 'target state')
            ->addOption('no-trigger', null, InputOption::VALUE_NONE, 'skip the state\'s on-enter actions (ignored for waiting)')
            ->addOption('worktree', null, InputOption::VALUE_REQUIRED, 'task worktree path to operate on instead of the cwd');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $state = State::tryFrom((string) $input->getArgument('state'));
        if (null === $state) {
            throw new \Pablo\Support\PabloError('unknown state '.var_export($input->getArgument('state'), true));
        }
        $ctx = $this->resolveCtx($store, $this->agents(), $input->getOption('worktree'));
        $lock = Store::taskLock($store, $ctx->task->project, $ctx->task->branch);
        try {
            StateMachine::enterState($ctx, $state, trigger: !$input->getOption('no-trigger'));
        } finally {
            $lock->release();
        }
        $output->writeln("{$ctx->task->branch}: state set to {$ctx->task->state->value}");

        return self::SUCCESS;
    }
}
