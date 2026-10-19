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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
        $this->addArgument('project', InputArgument::OPTIONAL, 'limit to one project (runs even when main_update.enabled is false)');
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
        } else {
            $projects = array_filter($projects, static fn ($cfg) => $cfg->mainUpdateEnabled);
        }

        $failed = false;
        foreach ($projects as $name => $cfg) {
            try {
                $line = $this->git->updatePrimary($cfg->repoPath, $cfg->primaryBranch, $cfg->primaryRemote)->render();
            } catch (PabloError $e) {
                $failed = true;
                $line = "❌ {$e->getMessage()}";
            }
            $output->writeln("[{$name}] main: {$line}");
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
