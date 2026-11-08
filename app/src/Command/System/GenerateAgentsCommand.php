<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\Files;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the agents from their bundled .md.template sources + the project
 * configs for the selected runtime. Everything goes under ~/.pablo/runtime
 * (PABLO_RUNTIME_DIR) — the sources may sit inside the read-only binary:
 *  - always, the shared render (AgentTemplates::renderedDir()):
 *    opencode/agents/<agent>.md plus the bundled commands and skills
 *    extracted to opencode/commands/ and opencode/skills/ (a symlink cannot
 *    point into the binary);
 *  - then whatever the runtime translates on top of it, in its own directory
 *    (RuntimeAgentFilesInterface::generate()).
 * `pablo system:install` runs this, then links the results into place.
 */
#[AsCommand(name: 'system:generate-agents', description: 'generate the agent definitions for the selected runtime from .md.template + project configs')]
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
            ->addOption('agents-dir', null, InputOption::VALUE_REQUIRED, 'template sources directory (default: the bundled opencode/agents)')
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, implode(' | ', AgentRuntimeRegistry::NAMES).' (default: agent_runtime / PABLO_AGENT_RUNTIME)')
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'where the shared agents/, commands/ and skills/ go (default: ~/.pablo/runtime/opencode)')
            ->addOption('runtime-dir', null, InputOption::VALUE_REQUIRED, 'where the runtime\'s own files go (default: ~/.pablo/runtime/<runtime>)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agentsDir = $input->getOption('agents-dir') ?? $this->agentTemplates->templatesDir();
        $outputDir = $input->getOption('output-dir') ?? AgentTemplates::renderedDir();
        $runtimeName = $input->getOption('runtime');
        $runtime = $this->agentLaunchers->runtimes()->resolve(null !== $runtimeName ? AgentRuntimeRegistry::checkName($runtimeName, 'runtime') : null);

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

            $outputPath = $outputDir.'/agents/'.$name.'.md';
            try {
                Files::write($outputPath, $content);
            } catch (PabloError) {
                $output->writeln("<error>Cannot write: {$outputPath}</error>");

                return self::FAILURE;
            }

            $output->writeln("Generated {$name}.md with providers: ".implode(', ', $enabledProviders ?: ['(none)']));
        }

        // The commands and skills ship as-is; extract them next to the agents
        // so every runtime links to real files on disk.
        try {
            foreach (Files::list(AgentTemplates::commandsDirFor($agentsDir), '*.md') as $source) {
                Files::copy($source, $outputDir.'/commands/'.basename($source));
            }
            foreach (Files::list(AgentTemplates::skillsDirFor($agentsDir), '*', dirsOnly: true) as $source) {
                Files::copy($source, $outputDir.'/skills/'.basename($source));
            }
        } catch (PabloError $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        try {
            $files = $runtime->agentFiles()->generate($agentsDir, $enabledProviders, $input->getOption('runtime-dir'));
        } catch (AgentTemplateError $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        return self::writeFiles($output, $files, $runtime->label());
    }

    /**
     * @param array<string, string> $files path => content
     */
    private static function writeFiles(OutputInterface $output, array $files, string $runtimeLabel): int
    {
        foreach ($files as $path => $content) {
            try {
                Files::write($path, $content);
            } catch (PabloError) {
                $output->writeln("<error>Cannot write: {$path}</error>");

                return self::FAILURE;
            }
            $output->writeln("Generated {$path} ({$runtimeLabel})");
        }

        return self::SUCCESS;
    }
}
