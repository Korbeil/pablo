<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Doctor\CheckResult;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Interactive-ish setup wizard: checks the required CLIs and walks the user
 * through fixing them one at a time. Each launch handles a single next step —
 * re-run after fixing to continue to the following check.
 */
#[AsCommand(name: 'system:setup', description: 'detect required CLIs and walk through installing/authenticating the missing ones, one step at a time')]
final class SetupCommand extends Command
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
        $clis = array_values(array_intersect($this->doctor->cliOrder(), $this->doctor->requiredClis($this->projects())));
        $results = $this->doctor->checkIds($clis);

        foreach ($results as $result) {
            if ($result->ok()) {
                continue;
            }
            $this->renderNextStep($output, $result);

            return self::SUCCESS;
        }

        $output->writeln($this->doctor->render($results));
        $output->writeln('All required CLIs are installed and authenticated.');

        return self::SUCCESS;
    }

    private function renderNextStep(OutputInterface $output, CheckResult $result): void
    {
        $output->writeln('');
        $output->writeln("❌ <comment>{$result->cli}</comment>: {$result->detail}");
        if (!$result->installed) {
            $install = $this->doctor->installCommand($result->cli);
            if (null !== $install) {
                $output->writeln("   Install: <info>{$install}</info>");
            }
            $docs = $this->doctor->docsUrl($result->cli);
            if (null !== $docs) {
                $output->writeln("   Docs: {$docs}");
            }
            if (null === $install && '' !== $result->hint) {
                $output->writeln("   {$result->hint}");
            }
        } elseif (!$result->authenticated && '' !== $result->hint) {
            $output->writeln("   {$result->hint}");
        }
        $output->writeln('');
        $output->writeln('Run the command above, then re-run this command to continue to the next check.');
    }
}
