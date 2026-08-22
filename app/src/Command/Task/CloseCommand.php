<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:close', description: 'close the current task (delete worktree + record)')]
final class CloseCommand extends Command
{
    public function __construct(
        private readonly GitRepoInterface $git,
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
            ->addOption('yes', null, InputOption::VALUE_NONE, 'close even if agents are active');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $agents = $this->agents();
        $ctx = $this->resolveCtx();
        $task = $ctx->task;
        $cfg = $ctx->cfg;
        $sessions = $agents->activeSessions($task->worktreePath);
        if ([] !== $sessions && !(bool) $input->getOption('yes')) {
            throw new PabloError(\count($sessions).' agent session(s) still active on this worktree — wait for them or re-run with --yes');
        }
        $lock = $store->taskLock($task->project, $task->branch);
        try {
            chdir($cfg->repoPath);
            $this->git->removeWorktree($cfg->repoPath, $task->worktreePath, $task->branch);
            $store->delete($task->project, $task->branch);
        } finally {
            $lock->release();
        }
        $output->writeln("closed {$task->branch} ({$task->project}): worktree removed, state cleared. The PR itself is untouched. You are now in {$cfg->repoPath}.");

        return self::SUCCESS;
    }
}
