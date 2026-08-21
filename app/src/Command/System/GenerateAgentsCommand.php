<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class GenerateAgentsCommand extends Command
{
    protected function configure(): void
    {
        $this->setName('system:generate-agents')
            ->setDescription('generate opencode agent .md files from .md.template + project configs')
            ->addOption('agents-dir', null, InputOption::VALUE_REQUIRED, 'agents directory (default: repo opencode/agents)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agentsDir = $input->getOption('agents-dir') ?? AgentTemplates::repoAgentsDir();

        $projectsDir = Config::projectsDir();
        if (!is_dir($projectsDir)) {
            $output->writeln('No projects directory found — generating agents with all providers enabled.');
        }
        $enabledProviders = AgentTemplates::enabledProviders($projectsDir);

        foreach (AgentTemplates::AGENT_NAMES as $name) {
            try {
                $content = AgentTemplates::render($agentsDir, $name, $enabledProviders);
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
