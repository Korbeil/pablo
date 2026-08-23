<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\OpenCodeUsage;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs inside the detached self-reinvocation subprocess: launches the agent
 * synchronously, then spawns internal:watch-agent to await its conclusion.
 * This is the single funnel every agent launch passes through, which makes
 * it the natural agent_run_started analytics hook.
 */
#[AsCommand(name: 'internal:launch-agent', hidden: true)]
final class InternalLaunchAgentCommand extends Command
{
    public function __construct(
        private readonly AnalyticsInterface $analytics,
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
            ->addOption('worktree', null, InputOption::VALUE_REQUIRED)
            ->addOption('agent', null, InputOption::VALUE_REQUIRED)
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED)
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $backend = (string) $input->getOption('backend');
        $worktree = (string) $input->getOption('worktree');
        $agent = (string) $input->getOption('agent');
        $prompt = (string) $input->getOption('prompt');
        $project = (string) $input->getOption('project');
        $branch = (string) $input->getOption('branch');
        $runId = bin2hex(random_bytes(8));
        // Record the attempt before launching: a failed/hanging launch must
        // still count as an agent run started.
        $this->analytics->agentRunStarted($project, $branch, $worktree, $agent, $backend, $runId, $this->time->utcnow(), $prompt);
        // Persist the run id onto the launch record so a sweep-emitted
        // agent_run_finished keeps the same id as its agent_run_started.
        $this->persistRunId($project, $branch, $agent, $runId);
        $agents = $this->agentLaunchers->create($backend);
        $handle = $agents->doLaunchAgent($worktree, $agent, $prompt);
        $agents->spawnWatcher($project, $branch, $handle, $agent, '', null, $runId, OpenCodeUsage::fingerprint($prompt));
        $agents->refreshAgentDisplayCache($project, $branch, $worktree);

        return self::SUCCESS;
    }

    private function persistRunId(string $project, string $branch, string $agent, string $runId): void
    {
        try {
            $store = $this->store();
            $lock = $store->taskLock($project, $branch);
            try {
                $task = $store->get($project, $branch);
                $agentEnum = Agent::tryByName($agent);
                $label = null !== $agentEnum ? $agentEnum->value : $agent;
                $existing = null !== $task ? ($task->agentLaunches[$label] ?? null) : null;
                if (null === $task || null === $existing || $existing->reported) {
                    return;
                }
                $task->agentLaunches[$label] = new AgentLaunch(
                    $existing->agent,
                    $existing->launchedAt,
                    $existing->attempts,
                    $existing->finishedAt,
                    runId: $runId,
                    reported: false,
                );
                $store->save($task);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // best-effort: the watcher argv carries the same run id anyway
        }
    }
}
