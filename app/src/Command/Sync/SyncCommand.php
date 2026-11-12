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
            ->addOption('apply', null, InputOption::VALUE_NONE, 'actually sync (default dry-run unless sync.auto_apply is set)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'only report, even when sync.auto_apply is set');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if ($input->getOption('apply') && $input->getOption('dry-run')) {
            throw new PabloError('--apply and --dry-run are mutually exclusive');
        }
        $projects = $this->projects();
        $project = $input->getArgument('project') ?: null;
        if (null !== $project) {
            if (!isset($projects[$project])) {
                throw $this->projectsLoader->unknownProject($project);
            }
            $projects = [$project => $projects[$project]];
        } else {
            $this->warnProjectErrors($output);
        }
        $store = $this->store();
        $agents = $this->agents();
        // null defers to each project's sync.auto_apply.
        $apply = match (true) {
            (bool) $input->getOption('apply') => true,
            (bool) $input->getOption('dry-run') => false,
            default => null,
        };
        foreach ($projects as $name => $cfg) {
            $reports = $this->sync->syncProject($cfg, $store, $apply, $agents);
            $output->writeln("# {$name}");
            $output->writeln(Sync::renderReports($reports));
        }

        return self::SUCCESS;
    }
}
