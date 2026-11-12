<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Task\TaskStateChanger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:state', description: 'force a task (by default the cwd\'s) to a state')]
final class StateCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly TaskStateChanger $changer,
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
            ->addTaskTarget()
            ->addOption('no-trigger', null, InputOption::VALUE_NONE, 'skip the state\'s on-enter actions (ignored for waiting)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $state = State::tryFrom((string) $input->getArgument('state'));
        if (null === $state) {
            throw new PabloError('unknown state '.var_export($input->getArgument('state'), true));
        }
        $ctx = $this->resolveTargetCtx($input);
        $task = $this->changer->forceState($ctx->task->project, $ctx->task->branch, $state, trigger: !$input->getOption('no-trigger'));
        $output->writeln("{$task->branch}: state set to {$task->state->value}");

        return self::SUCCESS;
    }
}
