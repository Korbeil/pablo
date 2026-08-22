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
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:waiting', description: 'toggle the waiting pause for the current task')]
final class WaitingCommand extends Command
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

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $ctx = $this->resolveCtx();
        $lock = $store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $endedIn = $this->stateMachine->toggleWaiting($ctx);
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
