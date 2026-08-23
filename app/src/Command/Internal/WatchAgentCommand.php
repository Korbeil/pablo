<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AgentRunRecord;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Analytics\OpenCodeUsage;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Support\ProcessRunner;
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
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
        private readonly OpenCodeUsage $usage = new OpenCodeUsage(new ProcessRunner()),
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
            ->addOption('run-id', null, InputOption::VALUE_REQUIRED, 'analytics run id from internal:launch-agent')
            ->addOption('prompt-fingerprint', null, InputOption::VALUE_REQUIRED, 'sha256 prefix of the launch prompt for usage attribution')
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
                    $this->recordAgentRunFinished($task, $launch->launchedAt, $finishedAt, $runId, $launch->agent->value, (string) ($input->getOption('backend') ?: 'orca'), (string) ($input->getOption('prompt-fingerprint') ?? ''));
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

    /**
     * Best-effort analytics for the concluded run: wall-clock duration plus
     * token/cost usage harvested from the local OpenCode storage. Must never
     * break the watcher — harvest() already swallows its own failures.
     */
    private function recordAgentRunFinished(\Pablo\Domain\Task $task, ?string $launchedAt, string $finishedAt, string $runId, string $agentName, string $backend, string $fingerprint): void
    {
        try {
            $durationS = null;
            if (null !== $launchedAt) {
                $durationS = max(0, $this->time->parseTs($finishedAt)->getTimestamp() - $this->time->parseTs($launchedAt)->getTimestamp());
            }
            $usage = $this->usage->harvest($task->worktreePath, $launchedAt ?? $finishedAt, '' !== $fingerprint ? $fingerprint : null);
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
