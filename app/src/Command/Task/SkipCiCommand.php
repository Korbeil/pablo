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
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:skip-ci', description: 'ignore failing CI checks and move the current task past ci-red')]
final class SkipCiCommand extends Command
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
        if (State::CiRed !== $ctx->task->state) {
            throw new PabloError("task is {$ctx->task->state->value}, not ci-red — nothing to skip");
        }
        $lock = $store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $ctx->task->ciIgnored = true;
            $this->stateMachine->enterState($ctx, State::ReadyToReview);
        } finally {
            $lock->release();
        }
        $output->writeln("{$ctx->task->branch}: CI results skipped, moved to {$ctx->task->state->value}");

        return self::SUCCESS;
    }
}
