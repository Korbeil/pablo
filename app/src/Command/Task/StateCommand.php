<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:state', description: 'force the current task to a state')]
final class StateCommand extends Command
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
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
        $ctx = $this->resolveCtx((string) $input->getOption('worktree'));
        $lock = $store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $this->stateMachine->enterState($ctx, $state, trigger: !$input->getOption('no-trigger'));
        } finally {
            $lock->release();
        }
        $output->writeln("{$ctx->task->branch}: state set to {$ctx->task->state->value}");

        return self::SUCCESS;
    }
}
