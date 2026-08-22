<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Support\RepoSlug;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'internal:watch-agent', hidden: true)]
final class WatchAgentCommand extends Command
{
    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly RepoSlug $repoSlug,
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
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, '', 'orca')
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->addOption('handle', null, InputOption::VALUE_REQUIRED)
            ->addOption('then', null, InputOption::VALUE_REQUIRED, '', null, ['pr-draft'])
            ->addOption('expect-state', null, InputOption::VALUE_REQUIRED)
            ->addOption('agent', null, InputOption::VALUE_REQUIRED)
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->agentLaunchers->create((string) $input->getOption('backend'));
        $agents->waitForHandle((string) $input->getOption('handle'));
        $store = $this->store();
        $project = (string) $input->getOption('project');
        $branch = (string) $input->getOption('branch');
        $agentName = $input->getOption('agent');
        $lock = $store->taskLock($project, $branch);
        try {
            $task = $store->get($project, $branch);
            if (null === $task) {
                return self::SUCCESS; // task closed while the agent ran
            }
            if (null !== $agentName) {
                $agent = Agent::tryByName((string) $agentName);
                $launch = null !== $agent ? ($task->agentLaunches[$agent->value] ?? null) : null;
                if (null !== $launch) {
                    // The agent's run has concluded; the ball is with the user
                    // (review the plan and commit-and-PR) until they act.
                    $task->agentLaunches[$agent->value] = new AgentLaunch(
                        $launch->agent,
                        $launch->launchedAt,
                        $launch->attempts,
                        $this->time->utcnow(),
                    );
                }
            }
            $expect = null !== $input->getOption('expect-state') ? State::tryFrom((string) $input->getOption('expect-state')) : null;
            if (null !== $expect && $task->state !== $expect) {
                $store->save($task);

                return self::SUCCESS; // state moved on
            }
            if ('pr-draft' === $input->getOption('then') && null !== $task->prNumber) {
                $cfg = $this->projects()[$project] ?? null;
                if (null !== $cfg) {
                    $this->gh->markDraft($this->repoSlug->for($cfg), $task->prNumber);
                }
            }
            $store->save($task);
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
