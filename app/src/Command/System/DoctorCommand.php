<?php

declare(strict_types=1);

namespace Pablo\Command\System;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Config\Config;
use Pablo\Config\ConfigIssue;
use Pablo\Config\ConfigValidator;
use Pablo\Doctor\AgentStaleness;
use Pablo\Doctor\Doctor;
use Pablo\Doctor\SchedulerHealth;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'system:doctor', description: 'check required CLIs are installed and authenticated')]
final class DoctorCommand extends Command
{
    use FormatsOutput;

    public function __construct(
        private readonly Doctor $doctor,
        private readonly AgentStaleness $agentStaleness,
        private readonly SchedulerHealth $schedulerHealth,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
        private readonly ConfigValidator $configValidator,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this->addFormatOption();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->wantsJson($input)) {
            return $this->executeJson($output);
        }

        // Agent settings that cannot work at all (a backend that cannot run the
        // runtime, an unknown runtime) come first: they are hard failures.
        $configErrors = $this->doctor->configErrors();
        if ([] !== $configErrors) {
            $output->writeln($this->doctor->renderConfigErrors($configErrors));
        }
        // The config files (system:config:validate): an invalid value, a
        // project file left out of dispatch or a missing path is a hard
        // failure, an unknown key a warning.
        $report = $this->configValidator->validate();
        $configLines = $this->renderConfigIssues($report->issues);
        if ('' !== $configLines) {
            $output->writeln($configLines);
        }
        if (null !== $report->globalError) {
            // Every other check reads it: nothing more to say until it loads.
            $output->writeln('   → fix ~/.pablo/config.yaml (pablo system:config:validate lists every problem)');

            return self::FAILURE;
        }
        $projects = $this->projects();
        $results = $this->doctor->checkAll($projects);
        $output->writeln($this->doctor->render($results));
        $detachWarning = $this->doctor->detachWarning();
        if (null !== $detachWarning) {
            $output->writeln($detachWarning);
        }
        // The user's hook command: a soft warning, never a failure.
        $hookWarnings = $this->doctor->hookWarnings($projects);
        if ([] !== $hookWarnings) {
            $output->writeln($this->doctor->renderHookWarnings($hookWarnings));
        }
        $failed = [] !== $configErrors || $report->hasErrors();
        foreach ($results as $r) {
            if (!$r->ok()) {
                $failed = true;
            }
        }

        // The selected runtime's generated agents: staleness is soft (cosmetic), a missing
        // or broken install is hard — same split as the dispatcher's CLI checks.
        $agentResults = $this->agentStaleness->check();
        $agentLines = $this->agentStaleness->render($agentResults);
        if ('' !== $agentLines) {
            $output->writeln('');
            $output->writeln('agents:');
            $output->writeln($agentLines);
        }

        // The background dispatcher: warnings only, never the exit code.
        $output->writeln('');
        $output->writeln('scheduler:');
        $output->writeln($this->schedulerHealth->lines($projects));

        if ($failed || $this->agentStaleness->hasHardFailure($agentResults)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Same checks and exit code as the table, one entry per check: status
     * ok / warn (soft: a stale agent) / fail (hard).
     */
    private function executeJson(OutputInterface $output): int
    {
        $checks = [];
        foreach ($this->doctor->configErrors() as $error) {
            $checks[] = ['group' => 'config', 'name' => 'config', 'status' => 'fail', 'message' => $error, 'hint' => ''];
        }
        $report = $this->configValidator->validate();
        foreach ($report->issues as $issue) {
            $checks[] = ['group' => 'config', 'name' => $issue->label.('' !== $issue->key ? ':'.$issue->key : ''), 'status' => $issue->isError() ? 'fail' : 'warn', 'message' => $issue->text(), 'hint' => '', 'file' => $issue->file];
        }
        if (null !== $report->globalError) {
            $this->writeJson($output, ['ok' => false, 'checks' => $checks]);

            return self::FAILURE;
        }
        $projects = $this->projects();
        foreach ($this->doctor->checkAll($projects) as $r) {
            $checks[] = ['group' => 'cli', 'name' => $r->cli, 'status' => $r->ok() ? 'ok' : 'fail', 'message' => $r->detail, 'hint' => $r->hint, 'installed' => $r->installed, 'authenticated' => $r->authenticated];
        }
        foreach ($this->doctor->hookWarnings($projects) as $warning) {
            $checks[] = ['group' => 'hooks', 'name' => 'hooks.command', 'status' => 'warn', 'message' => $warning, 'hint' => ''];
        }
        foreach ($this->agentStaleness->check() as $a) {
            $status = $a->ok() ? 'ok' : ($a->state->hard() ? 'fail' : 'warn');
            $checks[] = ['group' => 'agents', 'name' => $a->agent, 'status' => $status, 'message' => $a->detail, 'hint' => $a->hint];
        }
        $failed = [] !== array_filter($checks, static fn (array $c): bool => 'fail' === $c['status']);
        $this->writeJson($output, ['ok' => !$failed, 'checks' => $checks]);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The config section: errors as ❌, warnings (unknown keys) as ⚠️.
     *
     * @param list<ConfigIssue> $issues
     */
    private function renderConfigIssues(array $issues): string
    {
        $errors = array_values(array_map(static fn (ConfigIssue $i): string => $i->text(), array_filter($issues, static fn (ConfigIssue $i): bool => $i->isError())));
        $lines = [] !== $errors ? [$this->doctor->renderConfigErrors($errors)] : [];
        foreach ($issues as $issue) {
            if (!$issue->isError()) {
                $lines[] = \sprintf('⚠️  %-9s %s', 'config', $issue->text());
            }
        }

        return implode("\n", $lines);
    }
}
