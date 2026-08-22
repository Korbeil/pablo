<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Time;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:relaunch', description: 're-fire the current state\'s agent/startup launchers on the current task')]
final class RelaunchCommand extends Command
{
    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly Time $time,
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
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 're-fire only one label', null, ['task-analyst', 'startup-script', 'ci-analyst', 'pr-feedback', 'task-feedback']);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store();
        $ctx = $this->resolveCtx();
        $onlyRaw = $input->getOption('only') ?: null;
        $only = null !== $onlyRaw ? Agent::tryByName($onlyRaw) : null;
        $fired = [];
        $lock = $store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $task = $ctx->task;
            foreach ($this->stateMachine->specsFor($ctx, $task->state) as $spec) {
                if (null !== $only && $spec['label'] !== $only) {
                    continue;
                }
                [$fn, $args] = $spec['build']($ctx);
                $fn(...$args);
                $task->agentLaunches[$spec['label']->value] = new AgentLaunch($spec['label'], $this->time->utcnow(), 1);
                $fired[] = $spec['label']->value;
            }
            $store->save($task);
        } finally {
            $lock->release();
        }
        if ([] === $fired) {
            $output->writeln("{$ctx->task->branch}: nothing to relaunch in {$ctx->task->state->value} state"
                .(null !== $only ? " matching --only '{$only->value}'" : ''));

            return self::SUCCESS;
        }
        $output->writeln("{$ctx->task->branch}: re-launched ".implode(', ', $fired).' — check Orca for the new terminal tab');

        return self::SUCCESS;
    }
}
