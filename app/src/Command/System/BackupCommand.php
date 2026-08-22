<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Backup\Backup;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Task;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'archive:backup', description: 'create a restorable archive of all PABLO state (agent sessions excluded)')]
final class BackupCommand extends Command
{
    public function __construct(
        private readonly Backup $backup,
        private readonly \Pablo\Provider\Git\GitRepoInterface $git,
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
            ->addArgument('destination', InputArgument::OPTIONAL, 'output file or directory; defaults to ~/.pablo-backups/');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $pabloRoot = $this->backup->pabloRoot();
        $projects = $this->projects();
        $store = $this->store();
        $projectsDir = $this->projectsLoader->projectsDir();

        $dest = (string) ($input->getArgument('destination') ?? $this->backup->defaultBackupDir().'/'.$this->backup->defaultArchiveName());
        if (is_dir($dest)) {
            $dest = rtrim($dest, '/').'/'.$this->backup->defaultArchiveName();
        }

        $path = $this->backup->writeArchive($pabloRoot, $projectsDir, $projects, $store, $dest);

        $output->writeln("backup written: {$path}");
        $output->writeln("included state, stamps, logs, cache, project configs and Orca repo list from {$pabloRoot} (agents/ excluded)");
        foreach ($projects as $cfg) {
            $branches = array_map(
                static fn (Task $t) => $t->branch,
                $store->allTasks($cfg->name),
            );
            $origin = (string) $this->git->originUrl($cfg->repoPath);
            $output->writeln("  - {$cfg->name} (origin {$origin}): ".(\count($branches) > 0 ? implode(', ', $branches) : 'no tasks'));
        }

        return self::SUCCESS;
    }
}
