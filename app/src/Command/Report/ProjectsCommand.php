<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'project:list', description: 'list configured projects')]
final class ProjectsCommand extends Command
{
    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->projects();
        ksort($projects);
        foreach ($projects as $name => $cfg) {
            $output->writeln("{$name}\t{$cfg->type}\t{$cfg->provider}\t{$cfg->repoPath}");
        }

        return self::SUCCESS;
    }
}
