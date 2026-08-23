<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:close', description: 'close a task (delete worktree + record), by default the cwd\'s task')]
final class CloseCommand extends Command
{
    public function __construct(
        private readonly GitRepoInterface $git,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addArgument('branch', InputArgument::OPTIONAL, 'task branch to close instead of the cwd\'s task')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project owning <branch> (needed only when several projects have a task with that branch)')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'close even if agents are active');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $agents = $this->agents();
        [$task, $cfg] = $this->resolveTarget((string) $input->getArgument('branch'), (string) $input->getOption('project'));
        $sessions = $agents->activeSessions($task->worktreePath);
        if ([] !== $sessions && !(bool) $input->getOption('yes')) {
            throw new PabloError(\count($sessions).' agent session(s) still active on this worktree — wait for them or re-run with --yes');
        }
        $lock = $store->taskLock($task->project, $task->branch);
        try {
            chdir($cfg->repoPath);
            $hadWorktree = is_dir($task->worktreePath);
            $this->git->removeWorktree($cfg->repoPath, $task->worktreePath, $task->branch);
            // Before the record disappears: the close event is what preserves
            // the task's final metadata for analytics.
            $this->analytics->taskClosed($task);
            $store->delete($task->project, $task->branch);
        } finally {
            $lock->release();
        }
        $cleanup = $hadWorktree ? 'worktree removed' : 'stale worktree pruned';
        $output->writeln("closed {$task->branch} ({$task->project}): {$cleanup}, state cleared. The PR itself is untouched. You are now in {$cfg->repoPath}.");

        return self::SUCCESS;
    }

    /** @return array{Task, ProjectConfig} */
    private function resolveTarget(string $branch, string $project): array
    {
        if ('' === $branch && '' === $project) {
            $ctx = $this->resolveCtx();

            return [$ctx->task, $ctx->cfg];
        }
        if ('' === $branch) {
            throw new PabloError('--project needs a branch: pablo task:close <branch> --project <name>');
        }
        if ('' !== $project) {
            $task = $this->store()->get($project, $branch);
            if (null === $task) {
                throw new PabloError("no PABLO task '{$branch}' for project '{$project}'");
            }

            return [$task, $this->cfgFor($task)];
        }
        $matches = array_values(array_filter(
            $this->store()->allTasks(),
            static fn (Task $t): bool => $t->branch === $branch,
        ));
        if ([] === $matches) {
            throw new PabloError("no PABLO task named '{$branch}'");
        }
        if (\count($matches) > 1) {
            $projects = array_map(static fn (Task $t): string => $t->project, $matches);
            sort($projects);

            throw new PabloError(\sprintf("'%s' exists in several projects (%s) — add --project <name>", $branch, implode(', ', $projects)));
        }

        return [$matches[0], $this->cfgFor($matches[0])];
    }

    private function cfgFor(Task $task): ProjectConfig
    {
        $cfg = $this->projects()[$task->project] ?? null;
        if (null === $cfg) {
            throw new PabloError("task {$task->branch} belongs to project '{$task->project}', which has no config under projects/ anymore");
        }

        return $cfg;
    }
}
