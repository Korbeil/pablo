<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Task\TaskStateChanger;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:skip-ci', description: 'ignore failing CI checks and move a task (by default the cwd\'s) past ci-red')]
final class SkipCiCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly TaskStateChanger $changer,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this->addTaskTarget();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->resolveTargetCtx($input);
        $task = $this->changer->skipCi($ctx->task->project, $ctx->task->branch);
        $output->writeln("{$task->branch}: CI results skipped, moved to {$task->state->value}");

        return self::SUCCESS;
    }
}
