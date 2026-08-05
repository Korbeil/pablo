<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Backup\Backup;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Task;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class BackupCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('archive:backup')
            ->setDescription('create a restorable archive of all PABLO state (agent sessions excluded)')
            ->addArgument('destination', InputArgument::OPTIONAL, 'output file or directory; defaults to ~/.pablo-backups/');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $pabloRoot = Backup::pabloRoot();
        $projects = $this->projects();
        $store = $this->store();
        $projectsDir = Config::projectsDir();

        $dest = (string) ($input->getArgument('destination') ?? Backup::defaultBackupDir().'/'.Backup::defaultArchiveName());
        if (is_dir($dest)) {
            $dest = rtrim($dest, '/').'/'.Backup::defaultArchiveName();
        }

        $path = Backup::writeArchive($pabloRoot, $projectsDir, $projects, $store, $dest);

        $output->writeln("backup written: {$path}");
        $output->writeln("included state, stamps, logs, cache and project configs from {$pabloRoot} (agents/ excluded)");
        foreach ($projects as $cfg) {
            $branches = array_map(
                static fn (Task $t) => $t->branch,
                $store->allTasks($cfg->name),
            );
            $origin = (string) \Pablo\Provider\Git\GitRepo::originUrl($cfg->repoPath);
            $output->writeln("  - {$cfg->name} (origin {$origin}): ".(\count($branches) > 0 ? implode(', ', $branches) : 'no tasks'));
        }

        return self::SUCCESS;
    }
}
