<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:retrigger-ci', description: 're-run all GitHub Actions jobs for a task (by default the cwd\'s)')]
final class RetriggerCiCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly RepoSlug $repoSlug,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        protected readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addTaskTarget();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store;
        $ctx = $this->resolveTargetCtx($input);
        $repo = $this->repoSlug->for($ctx->cfg);
        $lock = $store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $runIds = $this->gh->rerunCi($repo, $ctx->task->branch);
            if (null !== $ctx->task->prNumber) {
                $this->gh->markDraft($repo, $ctx->task->prNumber);
            }
        } finally {
            $lock->release();
        }
        $output->writeln("{$ctx->task->branch}: re-triggered CI — ".\count($runIds).' workflow run(s) ('.implode(', ', $runIds).')');

        return self::SUCCESS;
    }
}
