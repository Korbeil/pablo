<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Task\TaskCloser;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:close', description: 'close a task (delete worktree + record), by default the cwd\'s task')]
final class CloseCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly TaskCloser $closer,
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
            ->addTaskTarget()
            ->addOption('yes', null, InputOption::VALUE_NONE, 'close even if agents are active');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->resolveTargetCtx($input);
        $task = $ctx->task;
        $cfg = $ctx->cfg;
        $sessions = $this->agents()->activeSessions($task->worktreePath);
        if ([] !== $sessions && !(bool) $input->getOption('yes')) {
            throw new PabloError(\count($sessions).' agent session(s) still active on this worktree — wait for them or re-run with --yes');
        }
        // Out of the worktree first: it is about to be deleted.
        chdir($cfg->repoPath);
        $cleanup = $this->closer->close($task->project, $task->branch, force: true);
        $output->writeln("closed {$task->branch} ({$task->project}): {$cleanup}, state cleared. The PR itself is untouched. You are now in {$cfg->repoPath}.");

        return self::SUCCESS;
    }
}
