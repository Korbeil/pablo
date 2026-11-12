<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Config\Config;
use Pablo\Config\ConfigIssue;
use Pablo\Config\ConfigReport;
use Pablo\Config\ConfigValidator;
use Pablo\Doctor\Doctor;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks ~/.pablo/config.yaml and every projects/*.yaml without changing
 * anything: invalid values and broken paths are errors (exit 1), unknown keys
 * are warnings (exit 0). See ConfigValidator.
 */
#[AsCommand(name: 'system:config:validate', description: 'check ~/.pablo/config.yaml and every project file: invalid values, unknown keys, missing paths')]
final class ConfigValidateCommand extends Command
{
    use FormatsOutput;

    public function __construct(
        private readonly ConfigValidator $validator,
        private readonly Doctor $doctor,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this->addFormatOption();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $report = $this->report();

        if ($json) {
            $this->writeJson($output, [
                'ok' => !$report->hasErrors(),
                'errors' => \count($report->errors()),
                'warnings' => \count($report->warnings()),
                'files' => $report->files,
                'issues' => array_map(static fn (ConfigIssue $i): array => $i->toArray(), $report->issues),
            ]);
        } else {
            $this->render($report, $output);
        }

        return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
    }

    /** The files' issues, plus agent settings that cannot work (an unknown runtime or ADE, an ADE refusing the runtime). */
    private function report(): ConfigReport
    {
        $report = $this->validator->validate();
        foreach ($this->doctor->configErrors() as $error) {
            // A config.yaml that does not load already says why.
            if ($error === $report->globalError) {
                continue;
            }
            $report = $report->withIssues(ConfigIssue::error($report->globalFile, ConfigValidator::GLOBAL_LABEL, '', $error));
        }

        return $report;
    }

    private function render(ConfigReport $report, OutputInterface $output): void
    {
        $shown = [];
        foreach ([...$report->files, ...array_map(static fn (ConfigIssue $i): string => $i->file, $report->issues)] as $file) {
            if (isset($shown[$file])) {
                continue;
            }
            $shown[$file] = true;
            $issues = $report->issuesOf($file);
            $errors = array_filter($issues, static fn (ConfigIssue $i): bool => $i->isError());
            $output->writeln(\sprintf('%s %s', [] !== $errors ? '❌' : ([] !== $issues ? '⚠️ ' : '✅'), $file));
            foreach ($issues as $issue) {
                $output->writeln(\sprintf('   %s %s', $issue->isError() ? '❌' : '⚠️ ', $issue->detail()));
            }
        }
        $errors = \count($report->errors());
        $warnings = \count($report->warnings());
        $output->writeln('');
        $output->writeln(\sprintf(
            '%d file%s checked: %d error%s, %d warning%s',
            \count($report->files),
            1 === \count($report->files) ? '' : 's',
            $errors,
            1 === $errors ? '' : 's',
            $warnings,
            1 === $warnings ? '' : 's',
        ));
    }
}
