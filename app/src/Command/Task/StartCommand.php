<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Command\UsesProjects;
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
    use UsesProjects;
    public const SUMMARY_MAX_WORDS = TaskStarter::SUMMARY_MAX_WORDS;

    public function __construct(
        private readonly TaskStarter $starter,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project name for plain-prompt tasks', null, $this->suggestions()->projectCompleter())
            ->addOption('yolo', null, InputOption::VALUE_NEGATABLE, 'YOLO mode for this task: PABLO implements each plan and runs /pablo-commit-and-pr itself (default: the project\'s yolo.enabled)')
            ->addArgument('input', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'issue URL or task prompt');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $text = trim(implode(' ', (array) $input->getArgument('input')));
        if ('' === $text) {
            throw new PabloError('usage: pablo task:start <issue-url> | pablo task:start --project <name> "<prompt>"');
        }

        $yolo = $input->getOption('yolo');
        $result = $this->starter->start($text, $input->getOption('project') ?: null, \is_bool($yolo) ? $yolo : null);

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
        if ($result->yolo) {
            $output->writeln('YOLO mode: PABLO will implement the plan and run /pablo-commit-and-pr on its own');
        }

        return self::SUCCESS;
    }
}
