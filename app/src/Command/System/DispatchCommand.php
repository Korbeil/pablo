<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Dispatch\Dispatch;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:dispatch', description: 'cron entry point: run due sync/poll/main jobs for all projects')]
final class DispatchCommand extends Command
{
    public function __construct(
        private readonly Dispatch $dispatch,
        private readonly Store $store,
        private readonly Config $projectsLoader,
    ) {
        parent::__construct();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        return $this->dispatch->run($this->projectsLoader->loadProjects(), $this->store, configErrors: $this->projectsLoader->projectErrors());
    }
}
