<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Listing\Listing;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:list', description: 'active task worktrees and their states', aliases: ['tasks'])]
final class TasksCommand extends Command
{
    public function __construct(
        private readonly Listing $listing,
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
            ->addOption('live', null, InputOption::VALUE_NONE, 'fetch fresh Tracker/PR/Agents data for display only')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'fetch fresh data and persist it as the new cache before rendering');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $render = $this->listing->tasksTable(
            $this->projects(),
            $this->store(),
            $this->agents(),
            (bool) $input->getOption('live'),
            (bool) $input->getOption('refresh'),
        );
        $output->writeln($render);

        return self::SUCCESS;
    }
}
