<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCodeRuntime;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the agents from their .md.template sources + the project configs
 * for the selected runtime: OpenCode .md files next to the templates, and —
 * under Claude Code — the `--agents` file (~/.pablo/claude/agents.json) plus
 * the Claude Code rendering of the commands (claude/commands/, git-ignored).
 */
#[AsCommand(name: 'system:generate-agents', description: 'generate the agent definitions (OpenCode .md files, or Claude Code agents.json + command) from .md.template + project configs')]
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
            ->addOption('agents-dir', null, InputOption::VALUE_REQUIRED, 'agents directory (default: repo opencode/agents)')
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, 'opencode | claude-code (default: agent_runtime / PABLO_AGENT_RUNTIME)')
            ->addOption('claude-dir', null, InputOption::VALUE_REQUIRED, 'where agents.json goes under claude-code (default: ~/.pablo/claude)')
            ->addOption('claude-commands-dir', null, InputOption::VALUE_REQUIRED, 'where the Claude Code commands go (default: repo claude/commands)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agentsDir = $input->getOption('agents-dir') ?? $this->agentTemplates->repoAgentsDir();
        $runtime = $input->getOption('runtime') ?? $this->agentLaunchers->runtimes()->resolveName();
        if (!\in_array($runtime, AgentRuntimeInterface::NAMES, true)) {
            throw new PabloError("unknown runtime '{$runtime}' (expected one of: ".implode(', ', AgentRuntimeInterface::NAMES).')');
        }

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

        if (AgentRuntimeInterface::CLAUDE_CODE !== $runtime) {
            return self::SUCCESS;
        }

        return $this->generateClaudeCode($input, $output, $agentsDir, $enabledProviders);
    }

    /**
     * @param list<string> $providers
     */
    private function generateClaudeCode(InputInterface $input, OutputInterface $output, string $agentsDir, array $providers): int
    {
        $claude = new ClaudeCodeAgents($this->agentTemplates);
        $claudeDir = $input->getOption('claude-dir') ?? ClaudeCodeRuntime::defaultDir();
        $commandsDir = $input->getOption('claude-commands-dir') ?? $this->agentTemplates->repoClaudeDir().'/commands';

        try {
            $files = [$claudeDir.'/agents.json' => $claude->renderAgentsJson($agentsDir, $providers)];
            foreach (glob(AgentTemplates::commandsDirFor($agentsDir).'/*.md') ?: [] as $source) {
                $files[$commandsDir.'/'.basename($source)] = $claude->renderCommand($source);
            }
        } catch (AgentTemplateError $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        foreach ($files as $path => $content) {
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0o777, true);
            }
            if (false === @file_put_contents($path, $content)) {
                $output->writeln("<error>Cannot write: {$path}</error>");

                return self::FAILURE;
            }
            $output->writeln("Generated {$path} (Claude Code)");
        }

        return self::SUCCESS;
    }
}
