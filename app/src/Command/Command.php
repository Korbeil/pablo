<?php

declare(strict_types=1);

namespace Pablo\Command;

use Pablo\Agents\AgentLauncher;
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
 * Base for every pablo subcommand. Services may be injected (from the DI
 * container) or built lazily; PabloError routes to stderr + non-zero exit.
 */
abstract class Command extends SymfonyCommand
{
    private ?Store $store;
    private ?AgentLauncherInterface $agents;

    public function __construct(?Store $store = null, ?AgentLauncherInterface $agents = null)
    {
        $this->store = $store;
        $this->agents = $agents;
        parent::__construct();
    }

    protected function store(): Store
    {
        return $this->store ??= new Store();
    }

    protected function agents(): AgentLauncherInterface
    {
        return $this->agents ??= AgentLauncher::create();
    }

    /** @return array<string, ProjectConfig> */
    protected function projects(): array
    {
        return Config::loadProjects(Config::projectsDir());
    }

    protected function resolveCtx(Store $store, AgentLauncherInterface $agents, ?string $worktree = null): TaskCtx
    {
        $task = null !== $worktree && '' !== $worktree
            ? $store->taskForWorktreePath($worktree)
            : $store->taskForCwd((string) getcwd());
        $cfg = $this->projects()[$task->project] ?? null;
        if (null === $cfg) {
            throw new PabloError("task {$task->branch} belongs to project '{$task->project}', which has no config under projects/ anymore");
        }

        return new TaskCtx(task: $task, cfg: $cfg, store: $store, agents: $agents);
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
