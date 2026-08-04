<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Poller\Poller;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class PollCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('poll')
            ->setDescription('run the task-state polling once')
            ->addArgument('project', InputArgument::OPTIONAL, 'limit to one project');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->projects();
        $project = $input->getArgument('project') ?: null;
        if (null !== $project) {
            if (!isset($projects[$project])) {
                throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
            }
            $projects = [$project => $projects[$project]];
        }
        $store = $this->store();
        $agents = $this->agents();
        foreach ($projects as $name => $cfg) {
            foreach (Poller::pollProject($cfg, $store, $agents) as $event) {
                $output->writeln("[{$name}] {$event}");
            }
        }

        return self::SUCCESS;
    }
}
