<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Doctor\Doctor;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class DoctorCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:doctor')->setDescription('check required CLIs are installed and authenticated');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $results = Doctor::checkAll($this->projects());
        $output->writeln(Doctor::render($results));
        foreach ($results as $r) {
            if (!$r->ok()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
