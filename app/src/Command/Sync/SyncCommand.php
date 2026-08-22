<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Git\Sync;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:run', description: 'sync task worktrees with the primary branch')]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly Sync $sync,
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
            $reports = $this->sync->syncProject($cfg, $store, $apply, $agents);
            $output->writeln("# {$name}");
            $output->writeln(Sync::renderReports($reports));
        }

        return self::SUCCESS;
    }
}
