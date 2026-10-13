<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\State;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Task\TaskAttacher;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Run inside any existing git worktree (PABLO-created or not) to adopt it as
 * a task. Unlike task:start nothing is created: the record is a pure write,
 * starting at <state> with no agent side effects.
 */
#[AsCommand(name: 'task:attach', description: 'attach the current worktree as a task starting at <state>', aliases: ['attach'])]
final class AttachCommand extends Command
{
    public function __construct(
        private readonly TaskAttacher $attacher,
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
            ->addArgument('state', InputArgument::REQUIRED, 'state the task starts in (e.g. in-progress)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project when the repo is not matched from configured projects');
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $state = State::tryFrom((string) $input->getArgument('state'));
        if (null === $state) {
            throw new PabloError('unknown state '.var_export($input->getArgument('state'), true));
        }

        $result = $this->attacher->attach((string) getcwd(), $state, $input->getOption('project') ?: null);

        $output->writeln("attached {$result->worktreePath} as task {$result->project}/{$result->branch} (state {$result->state->value})");

        return self::SUCCESS;
    }
}
