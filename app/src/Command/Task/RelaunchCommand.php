<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Agent;
use Pablo\StateMachine\StateMachine;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:relaunch', description: 're-fire the current state\'s agent/startup launchers on a task (by default the cwd\'s)')]
final class RelaunchCommand extends Command
{
    use TargetsTask;

    public function __construct(
        private readonly StateMachine $stateMachine,
        private readonly AdeRegistry $ades,
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        protected readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addTaskTarget()
            ->addOption('only', null, InputOption::VALUE_REQUIRED, 're-fire only one label', null, ['task-analyst', 'startup-script', 'ci-analyst', 'pr-feedback', 'task-feedback']);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $store = $this->store;
        $ctx = $this->resolveTargetCtx($input);
        $onlyRaw = $input->getOption('only') ?: null;
        $only = null !== $onlyRaw ? Agent::tryFrom($onlyRaw) : null;
        $fired = [];
        $lock = $store->taskLock($ctx->task->project, $ctx->task->branch);
        try {
            $task = $ctx->task;
            foreach ($this->stateMachine->agentsFor($task->state) as $agent) {
                if ((null === $only || $agent === $only) && $this->stateMachine->launch($ctx, $agent)) {
                    $fired[] = $agent->value;
                }
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
        $output->writeln("{$ctx->task->branch}: re-launched ".implode(', ', $fired).' — '.$this->ades->resolveOrDefault()->followHint());

        return self::SUCCESS;
    }
}
