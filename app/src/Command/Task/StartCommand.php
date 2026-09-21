<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Task\TaskStarter;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:start', description: 'start a task (issue URL or --project + prompt)', aliases: ['start'])]
final class StartCommand extends Command
{
    public const SUMMARY_MAX_WORDS = TaskStarter::SUMMARY_MAX_WORDS;

    public function __construct(
        private readonly TaskStarter $starter,
        Config $projectsLoader,
        Store $store,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project name for plain-prompt tasks')
            ->addArgument('input', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'issue URL or task prompt');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $text = trim(implode(' ', (array) $input->getArgument('input')));
        if ('' === $text) {
            throw new PabloError('usage: pablo task:start <issue-url> | pablo task:start --project <name> "<prompt>"');
        }

        $result = $this->starter->start($text, $input->getOption('project') ?: null);

        if ($result->reused) {
            $issueLine = null === $result->issueKey ? '' : " for {$result->issueKey}";
            $output->writeln("task{$issueLine} already exists: worktree {$result->worktreePath} (state {$result->reusedState}) — reusing it");

            return self::SUCCESS;
        }

        if (null !== $result->issueKey) {
            $output->writeln("started {$result->issueKey} ({$result->issueTitle}) in project {$result->project}");
        } else {
            $output->writeln("started task in project {$result->project}");
        }
        $output->writeln("worktree: {$result->worktreePath} (branch {$result->branch})");
        $output->writeln('state: in-progress — task-analyst is running');

        return self::SUCCESS;
    }
}
