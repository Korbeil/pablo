<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:doctor', description: 'check required CLIs are installed and authenticated')]
final class DoctorCommand extends Command
{
    public function __construct(
        private readonly Doctor $doctor,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $results = $this->doctor->checkAll($this->projects());
        $output->writeln($this->doctor->render($results));
        foreach ($results as $r) {
            if (!$r->ok()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
