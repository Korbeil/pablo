<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Command\Command;
use Pablo\Domain\State;
use Pablo\Provider\Gh\GhPr;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class WatchAgentCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('internal:watch-agent')
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->addOption('handle', null, InputOption::VALUE_REQUIRED)
            ->addOption('then', null, InputOption::VALUE_REQUIRED, '', null, ['pr-draft'])
            ->addOption('expect-state', null, InputOption::VALUE_REQUIRED)
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->agents();
        $agents->waitForHandle((string) $input->getOption('handle'));
        $store = $this->store();
        $project = (string) $input->getOption('project');
        $branch = (string) $input->getOption('branch');
        $lock = Store::taskLock($store, $project, $branch);
        try {
            $task = $store->get($project, $branch);
            if (null === $task) {
                return self::SUCCESS; // task closed while the agent ran
            }
            $expect = null !== $input->getOption('expect-state') ? State::tryFrom((string) $input->getOption('expect-state')) : null;
            if (null !== $expect && $task->state !== $expect) {
                return self::SUCCESS; // state moved on
            }
            if ('pr-draft' === $input->getOption('then') && null !== $task->prNumber) {
                $cfg = $this->projects()[$project] ?? null;
                if (null !== $cfg) {
                    GhPr::markDraft(RepoSlug::for($cfg), $task->prNumber);
                }
            }
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
