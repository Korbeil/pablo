<?php

declare(strict_types=1);

namespace Pablo\Command\Internal;

use Pablo\Agents\Ade\AdeRegistry;
use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\LaunchContext;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\PromptFingerprint;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Agent;
use Pablo\Domain\Time;
use Pablo\Store\Store;
use Pablo\Yolo\YoloPipeline;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs inside the detached self-reinvocation subprocess: launches the agent
 * synchronously, then spawns internal:watch-agent to await its conclusion.
 * This is the single funnel every agent launch passes through, which makes
 * it the natural agent_run_started analytics hook.
 *
 * YOLO mode: a task in YOLO mode gets its state agent run unattended under
 * a session PABLO records, and --follow-up-session runs one of the follow-up
 * steps (Yolo\YoloPipeline) in that session.
 */
#[AsCommand(name: 'internal:launch-agent', hidden: true)]
final class InternalLaunchAgentCommand extends Command
{
    public function __construct(
        private readonly AnalyticsInterface $analytics,
        private readonly Time $time,
        private readonly LaunchContext $launchContext,
        private readonly AdeRegistry $ades,
        private readonly Store $store,
        private readonly Config $projectsLoader,
        private readonly AgentLauncherFactory $agentLaunchers,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('backend', null, InputOption::VALUE_REQUIRED, '', AdeRegistry::DEFAULT)
            ->addOption('worktree', null, InputOption::VALUE_REQUIRED)
            ->addOption('agent', null, InputOption::VALUE_REQUIRED)
            ->addOption('prompt', null, InputOption::VALUE_REQUIRED)
            ->addOption('project', null, InputOption::VALUE_REQUIRED)
            ->addOption('branch', null, InputOption::VALUE_REQUIRED)
            ->addOption('follow-up-session', null, InputOption::VALUE_REQUIRED, 'YOLO mode: continue this runtime session')
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
        $followUpSession = $input->getOption('follow-up-session');
        $runId = bin2hex(random_bytes(8));
        $cfg = $this->project($project);
        if (\is_string($followUpSession) && '' !== $followUpSession) {
            // A YOLO follow-up: its prompt is complete, the session holds the
            // context already.
            $this->analytics->agentRunStarted($project, $branch, $worktree, $agent, $backend, $runId, $this->time->utcnow(), $prompt);
            $this->recordLaunch($project, $branch, $agent, $runId, null);
            $agents = $this->agentLaunchers->create($backend);
            $handle = $agents->doLaunchFollowUp($worktree, $agent, $followUpSession, $prompt, $cfg?->modelFor($agent));
        } else {
            // Done here, in the detached subprocess, so the poller never waits on
            // these fetches. The augmented prompt is the one actually sent, hence
            // the one fingerprinted below for usage attribution.
            $prompt = $this->withLaunchContext($backend, $agent, $cfg, $project, $branch, $prompt);
            // Record the attempt before launching: a failed/hanging launch must
            // still count as an agent run started.
            $this->analytics->agentRunStarted($project, $branch, $worktree, $agent, $backend, $runId, $this->time->utcnow(), $prompt);
            $agents = $this->agentLaunchers->create($backend);
            // A YOLO task's state agent runs unattended, under a session its
            // follow-ups continue.
            $sessionId = $this->isYolo($cfg, $project, $branch, $agent) ? $agents->newSessionId() : null;
            // Persist the run id onto the launch record so a sweep-emitted
            // agent_run_finished keeps the same id as its agent_run_started.
            $this->recordLaunch($project, $branch, $agent, $runId, $sessionId);
            // The agent's resolved model (project default_model /
            // models, per agent or per type / global default_model)
            // is looked up inside the detached subprocess — launch()'s argv stays
            // untouched. A config lookup failure must not fail the launch itself:
            // fall back to the agent file's frontmatter model.
            $handle = $agents->doLaunchAgent($worktree, $agent, $prompt, $cfg?->modelFor($agent), $sessionId);
            $adeSession = null !== $sessionId ? $agents->sessionOf($handle) : null;
            if (null !== $adeSession && $adeSession !== $sessionId) {
                // The ADE named the session itself (OpenChamber).
                $this->recordLaunch($project, $branch, $agent, $runId, $adeSession);
            }
        }
        $agents->spawnWatcher($project, $branch, $handle, $agent, $runId, PromptFingerprint::of($prompt));
        $agents->refreshAgentDisplayCache($project, $branch, $worktree);

        return self::SUCCESS;
    }

    /**
     * Append the pre-fetched context (see LaunchContext), unless the ADE
     * cannot carry it (AdeInterface::takesLaunchContext()). Best-effort: any
     * failure launches with the plain prompt.
     */
    private function withLaunchContext(string $backend, string $agent, ?ProjectConfig $cfg, string $project, string $branch, string $prompt): string
    {
        try {
            if (!$this->ades->resolve($backend)->takesLaunchContext()) {
                return $prompt;
            }
            $agentEnum = Agent::tryFrom($agent);
            $task = $this->store->get($project, $branch);
            if (null === $agentEnum || null === $cfg || null === $task) {
                return $prompt;
            }

            return $this->launchContext->augment($agentEnum, $task, $cfg, $prompt);
        } catch (\Throwable) {
            return $prompt;
        }
    }

    /** $project's config; null when it cannot be read (the launch goes on). */
    private function project(string $project): ?ProjectConfig
    {
        try {
            return $this->projectsLoader->loadProjects()[$project] ?? null;
        } catch (\Throwable) {
            return null;
        }
    }

    /** Whether $agent is a model agent of a task in YOLO mode. */
    private function isYolo(?ProjectConfig $cfg, string $project, string $branch, string $agent): bool
    {
        if (!(Agent::tryFrom($agent)?->runsModel() ?? false)) {
            return false;
        }
        try {
            $task = $this->store->get($project, $branch);
        } catch (\Throwable) {
            return false;
        }

        return null !== $cfg && null !== $task && YoloPipeline::isOn($task, $cfg);
    }

    /** The run id (and the session, when known) onto the launch record. */
    private function recordLaunch(string $project, string $branch, string $agent, string $runId, ?string $sessionId): void
    {
        try {
            $store = $this->store;
            $lock = $store->taskLock($project, $branch);
            try {
                $task = $store->get($project, $branch);
                $agentEnum = Agent::tryFrom($agent);
                $label = null !== $agentEnum ? $agentEnum->value : $agent;
                $existing = null !== $task ? ($task->agentLaunches[$label] ?? null) : null;
                if (null === $task || null === $existing || $existing->reported) {
                    return;
                }
                $task->agentLaunches[$label] = $existing->with(runId: $runId, reported: false, sessionId: $sessionId);
                $store->save($task);
            } finally {
                $lock->release();
            }
        } catch (\Throwable) {
            // best-effort: the watcher argv carries the same run id anyway
        }
    }
}
