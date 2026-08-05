<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Pablo\Listing\Listing;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class IssuesCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('show:issues')
            ->setDescription('issues assigned to me, per project')
            ->addArgument('project', InputArgument::OPTIONAL, 'limit to one project');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->projects();
        $project = $input->getArgument('project') ?: null;
        if (null !== $project && !isset($projects[$project])) {
            throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
        }
        $store = $this->store();
        $selected = null !== $project ? [$project => $projects[$project]] : $projects;
        foreach ($selected as $name => $cfg) {
            $output->writeln("# {$name}");
            $output->writeln(Listing::issuesTable($cfg, $store));
        }

        return self::SUCCESS;
    }
}
