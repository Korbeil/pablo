<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Agents\AgentRuns;
use Pablo\Analytics\AgentRunRecord;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\AgentLaunch;
use Pablo\Domain\State;
use Pablo\Domain\Time;
use Pablo\Hooks\Hooks;
use Pablo\Provider\Gh\GhPrInterface;
use Pablo\Store\Store;
use Pablo\Support\PabloError;
use Pablo\Support\RepoSlug;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'internal:watch-agent', hidden: true)]
final class WatchAgentCommand extends Command
{
    /** One wait slice when agents.timeout_minutes is 0 (no timeout). */
    public const UNBOUNDED_WAIT_S = 3600;

    public function __construct(
        private readonly GhPrInterface $gh,
        private readonly RepoSlug $repoSlug,
        private readonly Time $time,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
        private readonly UsageHarvesterInterface $usage,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
        private readonly Hooks $hooks = new Hooks(),
        private readonly ?AgentRuns $runs = null,
        private readonly GlobalConfig $globalConfig = new GlobalConfig(),
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }

    protected function configure(): void
    {
        $this
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, '', AdeRegistry::DEFAULT)
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
        $backend = (string) $input->getOption('backend');
        $handle = (string) $input->getOption('handle');
        $store = $this->store();
        $project = (string) $input->getOption('project');
        $branch = (string) $input->getOption('branch');
        $agentName = $input->getOption('agent');
        $this->awaitRun(null !== $this->runs ? $this->runs->launcher($backend) : $this->agentLaunchers->create($backend), $backend, $project, $branch, $handle, null !== $agentName ? (string) $agentName : null);
        $lock = $store->taskLock($project, $branch);
        $finished = null;
        try {
            $task = $store->get($project, $branch);
            if (null === $task) {
                return self::SUCCESS; // task closed while the agent ran
            }
            if (null !== $agentName) {
                $agent = Agent::tryByName((string) $agentName);
                $launch = null !== $agent ? ($task->agentLaunches[$agent->value] ?? null) : null;
                // The poller's sweep may already have reported this run (slow
                // watcher past its healing window); emitting again would
                // double-count it in analytics.
                if (null !== $launch && !$launch->reported) {
                    // The agent's run has concluded; the ball is with the user
                    // (review the plan and commit-and-PR) until they act. A run
                    // PABLO stopped keeps the time it was stopped at.
                    $finishedAt = $launch->finishedAt ?? $this->time->utcnow();
                    $runId = (string) ($input->getOption('run-id') ?? '');
                    if ('' === $runId) {
                        $runId = $launch->runId ?? bin2hex(random_bytes(8));
                    }
                    // reported=true in the same save as the stamp: whichever
                    // component reports a run, it must be reported once.
                    $task->agentLaunches[$agent->value] = $launch->reported($finishedAt, $runId);
                    $finished = $launch->agent;
                    $this->recordAgentRunFinished($task, $launch->launchedAt, $finishedAt, $runId, $launch->agent->value, (string) ($input->getOption('backend') ?: AdeRegistry::DEFAULT), (string) ($input->getOption('prompt-fingerprint') ?? ''));
                }
            }
            $expect = null !== $input->getOption('expect-state') ? State::tryFrom((string) $input->getOption('expect-state')) : null;
            // Otherwise the state moved on: nothing more than the stamp to save,
            // and no one to notify about a run the task has left behind.
            $current = null === $expect || $task->state === $expect;
            if (!$current) {
                $finished = null;
            }
            if ($current && 'pr-draft' === $input->getOption('then') && null !== $task->prNumber) {
                $cfg = $this->projects()[$project] ?? null;
                if (null !== $cfg) {
                    $this->gh->markDraft($this->repoSlug->for($cfg), $task->prNumber);
                }
            }
            $store->save($task);
        } finally {
            $lock->release();
        }
        // Only once the stamp is saved: the analysis is ready, the user's move.
        if (null !== $finished) {
            try {
                $cfg = $this->projects()[$project] ?? null;
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
     * Wait for the run to end. With agents.timeout_minutes set (default 60),
     * a run still at work when it expires is stopped — the same path as
     * agent:stop — and its record marked timed-out. With 0, wait as long as
     * it works. The startup script is never timed out.
     */
    private function awaitRun(AgentLauncherInterface $agents, string $backend, string $project, string $branch, string $handle, ?string $agentName): void
    {
        $worktree = $this->store()->get($project, $branch)->worktreePath ?? '';
        try {
            $timeoutM = $this->globalConfig->agentTimeoutMinutes();
        } catch (PabloError) {
            $timeoutM = GlobalConfig::AGENT_TIMEOUT_MINUTES; // a typo must not wedge or kill runs early
        }
        // The project's startup script is the user's own command, not an
        // agent's analysis: it is waited for as long as it runs, never timed out.
        if (Agent::StartupScript->value === $agentName) {
            $timeoutM = 0;
        }
        if (0 === $timeoutM) {
            do {
                $agents->waitForHandle($handle, self::UNBOUNDED_WAIT_S);
            } while ($agents->isRunning($handle, $worktree));

            return;
        }
        $agents->waitForHandle($handle, $timeoutM * 60);
        if ('' === $worktree || null === $agentName || null === $this->runs || !$agents->isRunning($handle, $worktree)) {
            return;
        }
        $this->runs->stopRun($project, $branch, $worktree, $agentName, $handle, $backend, AgentLaunch::TIMED_OUT);
    }

    /**
     * Best-effort analytics for the concluded run: wall-clock duration plus
     * token/cost usage harvested from the selected runtime's local storage. Must never
     * break the watcher — harvest() already swallows its own failures.
     */
    private function recordAgentRunFinished(\Pablo\Domain\Task $task, ?string $launchedAt, string $finishedAt, string $runId, string $agentName, string $backend, string $fingerprint): void
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
