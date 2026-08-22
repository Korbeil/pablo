<?php

declare(strict_types=1);

namespace Pablo\Command;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Base for every pablo subcommand. The three shared services are injected
 * through the constructor (the DI container autowires them); a subcommand
 * adds its own collaborators to its own constructor. PabloError routes to
 * stderr + non-zero exit.
 */
abstract class Command extends SymfonyCommand
{
    public function __construct(
        protected readonly Store $store,
        protected readonly Config $projectsLoader,
        protected readonly AgentLauncherFactory $agentLaunchers,
        protected readonly AgentLauncherInterface $agents,
    ) {
        parent::__construct();
    }

    protected function store(): Store
    {
        return $this->store;
    }

    /** @return array<string, ProjectConfig> */
    protected function projects(): array
    {
        return $this->projectsLoader->loadProjects();
    }

    protected function agents(): AgentLauncherInterface
    {
        return $this->agents;
    }

    protected function resolveCtx(?string $worktree = null): TaskCtx
    {
        $task = null !== $worktree && '' !== $worktree
            ? $this->store->taskForWorktreePath($worktree)
            : $this->store->taskForCwd((string) getcwd());
        $cfg = $this->projects()[$task->project] ?? null;
        if (null === $cfg) {
            throw new PabloError("task {$task->branch} belongs to project '{$task->project}', which has no config under projects/ anymore");
        }

        return new TaskCtx(task: $task, cfg: $cfg, store: $this->store, agents: $this->agents());
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            return $this->doExecute($input, $output);
        } catch (PabloError $e) {
            $err = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $err->writeln('pablo: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    abstract protected function doExecute(InputInterface $input, OutputInterface $output): int;
}
