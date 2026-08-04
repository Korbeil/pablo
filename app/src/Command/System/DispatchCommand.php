<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Dispatch\Dispatch;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DispatchCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('dispatch')->setDescription('cron entry point: run due sync/poll jobs for all projects');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        return Dispatch::run($this->projects(), $this->store());
    }
}
