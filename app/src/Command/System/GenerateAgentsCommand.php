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
use Pablo\Agents\Runtime\CopilotAgents;
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
 * under Claude Code — the `--agents` file (~/.pablo/claude/agents.json), the
 * pablo-<agent>.md subagent files (claude/agents/, linked into ~/.claude/agents
 * so /agents and the @ picker show them) plus the Claude Code rendering of the
 * commands (claude/commands/, git-ignored);
 * under Copilot, the `pablo-<agent>.agent.md` files and the commands as
 * skills (copilot/agents/ and copilot/skills/, git-ignored).
 */
#[AsCommand(name: 'system:generate-agents', description: 'generate the agent definitions (OpenCode .md files, Claude Code agents.json + command, or Copilot agents + skill) from .md.template + project configs')]
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
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, 'opencode | claude-code | copilot (default: agent_runtime / PABLO_AGENT_RUNTIME)')
            ->addOption('claude-dir', null, InputOption::VALUE_REQUIRED, 'where agents.json goes under claude-code (default: ~/.pablo/claude)')
            ->addOption('claude-commands-dir', null, InputOption::VALUE_REQUIRED, 'where the Claude Code commands go (default: repo claude/commands)')
            ->addOption('claude-agents-dir', null, InputOption::VALUE_REQUIRED, 'where the Claude Code pablo-<agent>.md subagent files go (default: repo claude/agents)')
            ->addOption('copilot-dir', null, InputOption::VALUE_REQUIRED, 'where the Copilot agents/ and skills/ go (default: repo copilot)');
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

        return match ($runtime) {
            AgentRuntimeInterface::CLAUDE_CODE => $this->generateClaudeCode($input, $output, $agentsDir, $enabledProviders),
            AgentRuntimeInterface::COPILOT => $this->generateCopilot($input, $output, $agentsDir, $enabledProviders),
            default => self::SUCCESS,
        };
    }

    /**
     * @param list<string> $providers
     */
    private function generateClaudeCode(InputInterface $input, OutputInterface $output, string $agentsDir, array $providers): int
    {
        $claude = new ClaudeCodeAgents($this->agentTemplates);
        $claudeDir = $input->getOption('claude-dir') ?? ClaudeCodeRuntime::defaultDir();
        $commandsDir = $input->getOption('claude-commands-dir') ?? $this->agentTemplates->repoClaudeDir().'/commands';
        $claudeAgentsDir = $input->getOption('claude-agents-dir') ?? $this->agentTemplates->repoClaudeDir().'/agents';

        try {
            $files = [$claudeDir.'/agents.json' => $claude->renderAgentsJson($agentsDir, $providers)];
            foreach ($claude->renderAgentFiles($agentsDir, $providers) as $file => $content) {
                $files[$claudeAgentsDir.'/'.$file] = $content;
            }
            foreach (glob(AgentTemplates::commandsDirFor($agentsDir).'/*.md') ?: [] as $source) {
                $files[$commandsDir.'/'.basename($source)] = $claude->renderCommand($source);
            }
        } catch (AgentTemplateError $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        return self::writeFiles($output, $files, 'Claude Code');
    }

    /**
     * @param list<string> $providers
     */
    private function generateCopilot(InputInterface $input, OutputInterface $output, string $agentsDir, array $providers): int
    {
        $copilot = new CopilotAgents($this->agentTemplates);
        $copilotDir = $input->getOption('copilot-dir') ?? $this->agentTemplates->repoCopilotDir();

        try {
            $files = [];
            foreach ($copilot->renderAgentFiles($agentsDir, $providers) as $file => $content) {
                $files[$copilotDir.'/agents/'.$file] = $content;
            }
            foreach (glob(AgentTemplates::commandsDirFor($agentsDir).'/*.md') ?: [] as $source) {
                $files[$copilotDir.'/skills/'.basename($source, '.md').'/SKILL.md'] = $copilot->renderSkill($source);
            }
        } catch (AgentTemplateError $e) {
            $output->writeln('<error>'.$e->getMessage().'</error>');

            return self::FAILURE;
        }

        return self::writeFiles($output, $files, 'Copilot');
    }

    /**
     * @param array<string, string> $files path => content
     */
    private static function writeFiles(OutputInterface $output, array $files, string $runtimeLabel): int
    {
        foreach ($files as $path => $content) {
            $dir = \dirname($path);
            if (!is_dir($dir)) {
                @mkdir($dir, 0o777, true);
            }
            if (false === @file_put_contents($path, $content)) {
                $output->writeln("<error>Cannot write: {$path}</error>");

                return self::FAILURE;
            }
            $output->writeln("Generated {$path} ({$runtimeLabel})");
        }

        return self::SUCCESS;
    }
}
