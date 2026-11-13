<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Command\Command;
use Pablo\Domain\Agent;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'internal:run-startup-script', hidden: true)]
final class InternalRunStartupScriptCommand extends Command
{
    protected function configure(): void
    {
        $this
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, '', AdeRegistry::DEFAULT)
            ->addOption('worktree', null, InputOption::VALUE_REQUIRED)
            ->addOption('script', null, InputOption::VALUE_REQUIRED)
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $project = (string) $input->getOption('project');
        $branch = (string) $input->getOption('branch');
        $worktree = (string) $input->getOption('worktree');
        $agents = $this->agentLaunchers->create((string) $input->getOption('backend'));
        $handle = $agents->doRunStartupScript($worktree, (string) $input->getOption('script'));
        // Stamp the launch finished once the script exits, as for an agent:
        // otherwise the poller sees an unfinished launch with no live session
        // and re-runs a script that already ran.
        $agents->spawnWatcher($project, $branch, $handle, Agent::StartupScript->value);
        $agents->refreshAgentDisplayCache($project, $branch, $worktree);

        return self::SUCCESS;
    }
}
