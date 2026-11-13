<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Analytics\AgentRunRecord;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Hooks\Hooks;
use Pablo\Store\Store;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'internal:watch-agent', hidden: true)]
final class WatchAgentCommand extends Command
{
    public function __construct(
        private readonly Time $time,
        private readonly Store $store,
        private readonly Config $projectsLoader,
        private readonly AgentLauncherFactory $agentLaunchers,
        private readonly UsageHarvesterInterface $usage,
        private readonly Hooks $hooks,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, '', AdeRegistry::DEFAULT)
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->addOption('handle', null, InputOption::VALUE_REQUIRED)
            ->addOption('agent', null, InputOption::VALUE_REQUIRED)
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'analytics run id from internal:launch-agent')
            ->addOption('prompt-fingerprint', null, InputOption::VALUE_REQUIRED, 'sha256 prefix of the launch prompt for usage attribution')
            ->setHidden(true);
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $agents = $this->agentLaunchers->create((string) $input->getOption('backend'));
        $agents->waitForHandle((string) $input->getOption('handle'));
        $store = $this->store;
        $project = (string) $input->getOption('project');
        $branch = (string) $input->getOption('branch');
        $agentName = $input->getOption('agent');
        $lock = $store->taskLock($project, $branch);
        $finished = null;
        try {
            $task = $store->get($project, $branch);
            if (null === $task) {
                return self::SUCCESS; // task closed while the agent ran
            }
            if (null !== $agentName) {
                $agent = Agent::tryFrom((string) $agentName);
                $launch = null !== $agent ? ($task->agentLaunches[$agent->value] ?? null) : null;
                // The poller's sweep may already have reported this run (slow
                // watcher past its healing window); emitting again would
                // double-count it in analytics.
                if (null !== $launch && !$launch->reported) {
                    // The agent's run has concluded; the ball is with the user
                    // (review the plan and commit-and-PR) until they act.
                    $finishedAt = $this->time->utcnow();
                    $runId = (string) ($input->getOption('run-id') ?? '');
                    if ('' === $runId) {
                        $runId = $launch->runId ?? bin2hex(random_bytes(8));
                    }
                    // reported=true in the same save as the stamp: whichever
                    // component reports a run, it must be reported once.
                    $task->agentLaunches[$agent->value] = new AgentLaunch(
                        $launch->agent,
                        $launch->launchedAt,
                        $launch->attempts,
                        $finishedAt,
                        runId: $runId,
                        reported: true,
                    );
                    $finished = $launch->agent;
                    $this->recordAgentRunFinished($task, $launch->launchedAt, $finishedAt, $runId, $launch->agent->value, (string) ($input->getOption('backend') ?: AdeRegistry::DEFAULT), (string) ($input->getOption('prompt-fingerprint') ?? ''));
                }
            }
            $store->save($task);
        } finally {
            $lock->release();
        }
        // Only once the stamp is saved: the analysis is ready, the user's move.
        if (null !== $finished) {
            try {
                $cfg = $this->projectsLoader->loadProjects()[$project] ?? null;
            } catch (\Throwable) {
                $cfg = null; // a hook is best-effort: never fail the watcher
            }
            if (null !== $cfg) {
                $this->hooks->agentFinished($cfg, $task, $finished);
            }
        }

        return self::SUCCESS;
    }

    /**
     * Best-effort analytics for the concluded run: wall-clock duration plus
     * token/cost usage harvested from the selected runtime's local storage. Must never
     * break the watcher — harvest() already swallows its own failures.
     */
    private function recordAgentRunFinished(Task $task, ?string $launchedAt, string $finishedAt, string $runId, string $agentName, string $backend, string $fingerprint): void
    {
        try {
            $durationS = null;
            if (null !== $launchedAt) {
                $durationS = max(0, $this->time->parseTs($finishedAt)->getTimestamp() - $this->time->parseTs($launchedAt)->getTimestamp());
            }
            // A startup script makes no LLM call: harvesting would pick up
            // whatever agent session ran in the worktree meanwhile.
            $usage = Agent::StartupScript->value === $agentName
                ? null
                : $this->usage->harvest($task->worktreePath, $launchedAt ?? $finishedAt, '' !== $fingerprint ? $fingerprint : null);
            $this->analytics->agentRunFinished(new AgentRunRecord(
                project: $task->project,
                branch: $task->branch,
                runId: $runId,
                agent: $agentName,
                backend: $backend,
                startedAt: $launchedAt,
                finishedAt: $finishedAt,
                durationS: $durationS,
                usage: $usage,
            ));
        } catch (\Throwable) {
            // analytics is best-effort by contract
        }
    }
}
