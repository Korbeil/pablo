<?php

declare(strict_types=1);

namespace Pablo\Command\Agent;

use Pablo\Agents\ActiveRun;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentRuns;
use Pablo\Command\Command;
use Pablo\Command\Task\TargetsTask;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\QuestionHelper;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ConfirmationQuestion;

#[AsCommand(name: 'agent:stop', description: 'stop the agent runs PABLO launched on a task (by default the cwd\'s)')]
final class StopCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly AgentRuns $runs,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this->addTaskTarget()
            ->addOption('agent', null, InputOption::VALUE_REQUIRED, 'only this agent\'s run, e.g. task-analyst')
            ->addOption('yes', null, InputOption::VALUE_NONE, 'do not ask for confirmation');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $task = $this->resolveTargetCtx($input)->task;
        $agent = (string) $input->getOption('agent');
        $agent = '' !== $agent ? $agent : null;
        $runs = array_values(array_filter(
            $this->runs->active($task->project),
            static fn (ActiveRun $r): bool => $r->branch === $task->branch && (null === $agent || $r->agent === $agent),
        ));
        if ([] === $runs) {
            $output->writeln(\sprintf('no PABLO agent run at work on %s (%s)%s', $task->branch, $task->project, null !== $agent ? " for {$agent}" : ''));

            return self::SUCCESS;
        }
        $list = implode(', ', array_map(static fn (ActiveRun $r): string => "{$r->agent} ({$r->ade} {$r->handle})", $runs));
        if (!(bool) $input->getOption('yes')) {
            if (!$input->isInteractive()) {
                throw new PabloError("would stop {$list} — re-run with --yes");
            }
            if (!(new QuestionHelper())->ask($input, $output, new ConfirmationQuestion("[y/N] Stop {$list}? ", false))) {
                $output->writeln('nothing stopped');

                return self::SUCCESS;
            }
        }

        $failed = false;
        foreach ($this->runs->stop($task, $agent) as $report) {
            $output->writeln(($report->outcome->stopped ? '✔ ' : '✘ ').$report->agent.': '.$report->outcome->detail);
            if (null !== $report->cleanup) {
                $output->writeln('  '.$report->cleanup);
            }
            $failed = $failed || !$report->outcome->stopped;
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
