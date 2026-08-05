<?php

declare(strict_types=1);

namespace Pablo\Command\Report;

use Pablo\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class ProjectsCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('show:projects')->setDescription('list configured projects');
    }

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
