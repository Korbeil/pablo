<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Command\UsesProjects;
use Pablo\Config\Config;
use Pablo\Dispatch\DispatchHealth;
use Pablo\Poller\Poller;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:poll', description: 'run the task-state polling once')]
final class PollCommand extends Command
{
    use UsesProjects;

    public function __construct(
        private readonly Poller $poller,
        private readonly DispatchHealth $health,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        private readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addProjectArgument();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $projects = $this->selectedProjects($input, $output);
        $store = $this->store;
        $agents = $this->agents;
        foreach ($projects as $name => $cfg) {
            foreach ($this->poller->pollProject($cfg, $store, $agents) as $event) {
                $output->writeln("[{$name}] {$event}");
            }
            // A manual run that works clears the dispatcher's failure streak and back-off.
            $this->health->recordSuccess($name, 'poll', microtime(true), $this->poller->lastCheckFailures());
        }

        return self::SUCCESS;
    }
}
