<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:info', description: 'dump a task record (by default the cwd\'s) as JSON')]
final class TaskCommand extends Command
{
    use TargetsTask;

    public const CURRENT_ALIAS = 'current';

    protected function configure(): void
    {
        $this
            ->addTaskTarget([self::CURRENT_ALIAS])
            ->addOption('json', null, InputOption::VALUE_NONE, 'emit JSON (accepted for back-compat)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        // `task:info current` predates the [<branch>] argument: keep it as an
        // alias of the cwd's task.
        if (self::CURRENT_ALIAS === $input->getArgument('branch')) {
            $input->setArgument('branch', null);
        }
        $ctx = $this->resolveTargetCtx($input);
        $payload = $ctx->task->toJson();
        $payload['repo_path'] = $ctx->cfg->repoPath;
        $payload['primary_branch'] = $ctx->cfg->primaryBranch;
        $output->writeln(json_encode(
            $payload,
            \JSON_THROW_ON_ERROR | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE,
        ));

        return self::SUCCESS;
    }
}
