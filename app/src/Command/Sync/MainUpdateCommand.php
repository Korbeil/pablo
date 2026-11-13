<?php

declare(strict_types=1);

namespace Pablo\Command\Sync;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'sync:main', description: "update each project's primary checkout to its remote primary branch")]
final class MainUpdateCommand extends Command
{
    public function __construct(
        private readonly GitRepoInterface $git,
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
            ->addProjectArgument('limit to one project (runs even when main_update.enabled is false)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'run every check and report what would happen, without touching the checkout (still fetches)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->selectedProjects($input, $output);
        if (!$input->getArgument('project')) {
            $projects = array_filter($projects, static fn ($cfg) => $cfg->mainUpdate->enabled);
        }

        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('dry run: no checkout is changed (git fetch still runs: it only updates remote-tracking refs)');
        }

        $failed = false;
        foreach ($projects as $name => $cfg) {
            try {
                $line = $this->git->updatePrimary($cfg->repo->path, $cfg->repo->primaryBranch, $cfg->repo->primaryRemote, $dryRun)->render();
            } catch (PabloError $e) {
                $failed = true;
                $line = "❌ {$e->getMessage()}";
            }
            $output->writeln("[{$name}] main".($dryRun ? ' (dry run)' : '').": {$line}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
