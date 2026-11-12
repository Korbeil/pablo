<?php

declare(strict_types=1);

namespace Pablo\Command\Agent;

use Pablo\Agents\ActiveRun;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentRuns;
use Pablo\Command\Command;
use Pablo\Command\FormatsOutput;
use Pablo\Command\Report\StatsCommand;
use Pablo\Config\Config;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'agent:list', description: 'agent runs PABLO launched that are still at work')]
final class ListCommand extends Command
{
    use FormatsOutput;

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
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'only this project\'s tasks')
            ->addFormatOption();
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $json = $this->wantsJson($input);
        $project = (string) $input->getOption('project');
        $runs = $this->runs->active('' !== $project ? $project : null);

        if ($json) {
            $this->writeJson($output, ['runs' => array_map(static fn (ActiveRun $r): array => $r->toJson(), $runs)]);

            return self::SUCCESS;
        }
        if ([] === $runs) {
            $output->writeln('no PABLO agent run at work');

            return self::SUCCESS;
        }
        $table = new Table($output);
        $table->setHeaders(['Task', 'Agent', 'ADE', 'Runtime', 'Handle', 'Started', 'Elapsed', 'Resume']);
        foreach ($runs as $run) {
            $table->addRow([
                $run->project.'/'.$run->branch,
                $run->agent,
                $run->ade,
                $run->runtime ?? '-',
                $run->handle,
                $run->startedAt ?? '-',
                null !== $run->elapsedS ? StatsCommand::humanDuration($run->elapsedS, true) : '-',
                $run->resume ?? '-',
            ]);
        }
        $table->render();

        return self::SUCCESS;
    }
}
