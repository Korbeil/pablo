<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

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
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'task:close', description: 'close a task (delete worktree + record), by default the cwd\'s task after confirmation')]
final class CloseCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly TaskCloser $closer,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        protected readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addTaskTarget()
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'don\'t ask for confirmation, and close even if agents are active');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $ctx = $this->resolveTargetCtx($input);
        $task = $ctx->task;
        $cfg = $ctx->cfg;
        $yes = (bool) $input->getOption('yes');
        $sessions = $this->agents->activeSessions($task->worktreePath);
        if ([] !== $sessions && !$yes) {
            throw new PabloError(\count($sessions).' agent session(s) still active on this worktree — wait for them or re-run with --yes');
        }
        // A task picked from the cwd was never named: deleting the worktree
        // you are standing in deserves a confirmation. A named task (<branch>
        // or --worktree) closes as before.
        $implicit = '' === (string) $input->getArgument('branch') && '' === (string) $input->getOption('worktree');
        if ($implicit && !$yes) {
            if (!$input->isInteractive()) {
                throw new PabloError("refusing to close {$task->branch} ({$task->project}), the current directory's task, without confirmation: name it (pablo task:close {$task->branch}) or pass --yes");
            }
            $question = "Close {$task->branch} ({$task->project})? This deletes its worktree {$task->worktreePath}, its local branch and the task record; the pull request is untouched.";
            if (!(new SymfonyStyle($input, $output))->confirm($question, false)) {
                $output->writeln('Nothing closed.');

                return self::SUCCESS;
            }
        }
        // Out of the worktree first: it is about to be deleted.
        chdir($cfg->repo->path);
        $cleanup = $this->closer->close($task->project, $task->branch, force: true);
        $output->writeln("closed {$task->branch} ({$task->project}): {$cleanup}, state cleared. The PR itself is untouched. You are now in {$cfg->repo->path}.");

        return self::SUCCESS;
    }
}
