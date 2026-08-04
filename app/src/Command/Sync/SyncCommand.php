<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Command\Command;
use Pablo\Provider\Git\Sync;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('sync')
            ->setDescription('sync task worktrees with the primary branch')
            ->addArgument('project', InputArgument::OPTIONAL, 'limit to one project')
            ->addOption('apply', null, InputOption::VALUE_NONE, 'actually sync (default dry-run unless sync.auto_apply is set)');
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
        $apply = $input->getOption('apply') ? true : null;
        foreach ($projects as $name => $cfg) {
            $reports = Sync::syncProject($cfg, $store, $apply, $agents);
            $output->writeln("# {$name}");
            $output->writeln(Sync::renderReports($reports));
        }

        return self::SUCCESS;
    }
}
