<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Pablo\Listing\Listing;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class TasksCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('tasks')
            ->setDescription('active task worktrees and their states')
            ->addOption('live', null, InputOption::VALUE_NONE, 'fetch fresh Tracker/PR/Agents data for display only')
            ->addOption('refresh', null, InputOption::VALUE_NONE, 'fetch fresh data and persist it as the new cache before rendering');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $render = Listing::tasksTable(
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
