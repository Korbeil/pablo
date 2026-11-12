<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Housekeeping\PruneItem;
use Pablo\Housekeeping\Pruner;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:prune', description: 'remove agent logs, service log archives and other versions\' container caches older than prune.keep_days (default 10); rotate the service logs')]
final class PruneCommand extends Command
{
    public function __construct(
        private readonly Pruner $pruner,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
        private readonly GlobalConfig $globalConfig,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('older-than', null, InputOption::VALUE_REQUIRED, 'remove what is older than this many days (default: prune.keep_days in ~/.pablo/config.yaml, else '.Pruner::DEFAULT_KEEP_DAYS.')')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'only list what would be rotated or removed, with sizes');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $days = $input->getOption('older-than');
        if (null === $days) {
            $days = $this->globalConfig->pruneKeepDays(Pruner::DEFAULT_KEEP_DAYS, Pruner::MIN_KEEP_DAYS);
        } elseif (!\is_string($days) || 1 !== preg_match('/^\d+$/', $days) || (int) $days < Pruner::MIN_KEEP_DAYS) {
            throw new PabloError('--older-than must be a whole number of days, at least '.Pruner::MIN_KEEP_DAYS);
        } else {
            $days = (int) $days;
        }
        $dryRun = (bool) $input->getOption('dry-run');

        $items = $this->pruner->plan($days);
        if ([] === $items) {
            $output->writeln('nothing to prune');

            return self::SUCCESS;
        }
        if (!$dryRun) {
            $this->pruner->apply($items);
        }
        foreach ($items as $item) {
            $output->writeln($item->describe($dryRun));
        }
        $bytes = array_sum(array_map(static fn (PruneItem $i): int => $i->freedBytes(), $items));
        $output->writeln(($dryRun ? 'would free ' : 'freed ').PruneItem::humanBytes($bytes));

        return self::SUCCESS;
    }
}
