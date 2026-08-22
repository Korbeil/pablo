<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:generate-agents', description: 'generate opencode agent .md files from .md.template + project configs')]
final class GenerateAgentsCommand extends Command
{
    public function __construct(
        private readonly AgentTemplates $agentTemplates,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('agents-dir', null, InputOption::VALUE_REQUIRED, 'agents directory (default: repo opencode/agents)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agentsDir = $input->getOption('agents-dir') ?? $this->agentTemplates->repoAgentsDir();

        $projectsDir = $this->projectsLoader->projectsDir();
        if (!is_dir($projectsDir)) {
            $output->writeln('No projects directory found — generating agents with all providers enabled.');
        }
        $enabledProviders = $this->agentTemplates->enabledProviders($projectsDir);

        foreach (AgentTemplates::AGENT_NAMES as $name) {
            try {
                $content = $this->agentTemplates->render($agentsDir, $name, $enabledProviders);
            } catch (AgentTemplateError $e) {
                $output->writeln('<error>'.$e->getMessage().'</error>');

                return self::FAILURE;
            }

            $outputPath = $agentsDir.'/'.$name.'.md';

            $written = @file_put_contents($outputPath, $content);
            if (false === $written) {
                $output->writeln("<error>Cannot write: {$outputPath}</error>");

                return self::FAILURE;
            }

            $output->writeln("Generated {$name}.md with providers: ".implode(', ', $enabledProviders ?: ['(none)']));
        }

        return self::SUCCESS;
    }
}
