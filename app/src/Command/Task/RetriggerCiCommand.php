<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Command\Command;
use Pablo\Provider\Gh\GhPr;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class RetriggerCiCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('task:retrigger-ci')->setDescription('re-run all GitHub Actions jobs for the current task');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $ctx = $this->resolveCtx($store, $this->agents());
        $repo = RepoSlug::for($ctx->cfg);
        $lock = Store::taskLock($store, $ctx->task->project, $ctx->task->branch);
        try {
            $runIds = GhPr::rerunCi($repo, $ctx->task->branch);
            if (null !== $ctx->task->prNumber) {
                GhPr::markDraft($repo, $ctx->task->prNumber);
            }
        } finally {
            $lock->release();
        }
        $output->writeln("{$ctx->task->branch}: re-triggered CI — ".\count($runIds).' workflow run(s) ('.implode(', ', $runIds).')');

        return self::SUCCESS;
    }
}
