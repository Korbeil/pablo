<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Command\Command;
use Pablo\Doctor\AgentStaleness;
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
        $failed = false;
        foreach ($results as $r) {
            if (!$r->ok()) {
                $failed = true;
            }
        }

        // Generated opencode agents: staleness is soft (cosmetic), a missing
        // or broken install is hard — same split as the dispatcher's CLI checks.
        $agentResults = AgentStaleness::check();
        $agentLines = AgentStaleness::render($agentResults);
        if ('' !== $agentLines) {
            $output->writeln('');
            $output->writeln($agentLines);
        }

        if ($failed || AgentStaleness::hasHardFailure($agentResults)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
