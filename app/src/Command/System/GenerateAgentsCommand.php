<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentFilesGenerator;
use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\Runtime\AgentRuntimeRegistry;
use Pablo\Command\Command;
use Pablo\Install\InstallLog;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders the agents for the selected runtime into ~/.pablo/runtime (see
 * AgentFilesGenerator), each file reported as created, updated or
 * unchanged. `pablo system:install` does the same, then links the results
 * into place.
 */
#[AsCommand(name: 'system:generate-agents', description: 'generate the agent definitions for the selected runtime from .md.template + project configs')]
final class GenerateAgentsCommand extends Command
{
    public function __construct(
        private readonly AgentFilesGenerator $generator,
        private readonly AgentRuntimeRegistry $runtimes,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('agents-dir', null, InputOption::VALUE_REQUIRED, 'template sources directory (default: the bundled opencode/agents)')
            ->addOption('runtime', null, InputOption::VALUE_REQUIRED, implode(' | ', AgentRuntimeRegistry::NAMES).' (default: agent_runtime / PABLO_AGENT_RUNTIME)', null, AgentRuntimeRegistry::NAMES)
            ->addOption('output-dir', null, InputOption::VALUE_REQUIRED, 'where the shared agents/, commands/ and skills/ go (default: ~/.pablo/runtime/opencode)')
            ->addOption('runtime-dir', null, InputOption::VALUE_REQUIRED, 'where the runtime\'s own files go (default: ~/.pablo/runtime/<runtime>)');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $runtimeName = $input->getOption('runtime');
        $runtime = $this->runtimes->resolve(null !== $runtimeName ? AgentRuntimeRegistry::checkName($runtimeName, 'runtime') : null);

        $log = new InstallLog($output);
        try {
            $this->generator->generate($runtime, $log, $input->getOption('agents-dir'), $input->getOption('output-dir'), $input->getOption('runtime-dir'));
        } catch (AgentTemplateError|PabloError $e) {
            $log->close();
            $output->writeln('<error>'.OutputFormatter::escape($e->getMessage()).'</error>');

            return self::FAILURE;
        }
        $log->summary();

        return self::SUCCESS;
    }
}
