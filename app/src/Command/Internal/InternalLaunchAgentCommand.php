<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\AgentLauncher;
use Pablo\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class InternalLaunchAgentCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('internal:launch-agent')
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, '', 'orca')
            ->addOption('worktree', null, InputOption::VALUE_REQUIRED)
            ->addOption('agent', null, InputOption::VALUE_REQUIRED)
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED)
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agents = AgentLauncher::create((string) $input->getOption('backend'));
        $handle = $agents->doLaunchAgent((string) $input->getOption('worktree'), (string) $input->getOption('agent'), (string) $input->getOption('prompt'));
        $agents->spawnWatcher(
            (string) $input->getOption('project'),
            (string) $input->getOption('branch'),
            $handle,
            (string) $input->getOption('agent'),
            '',
        );
        $agents->refreshAgentDisplayCache((string) $input->getOption('project'), (string) $input->getOption('branch'), (string) $input->getOption('worktree'));

        return self::SUCCESS;
    }
}
