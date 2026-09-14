<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherFactory;
use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
use Pablo\Analytics\NullAnalytics;
use Pablo\Command\Command;
use Pablo\Config\Config;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepoInterface;
use Pablo\Provider\Tracker\ProviderRegistryInterface;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\Naming;
use Pablo\Support\PabloError;
use Pablo\Support\TaskSummarizer;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'task:start', description: 'start a task (issue URL or --project + prompt)', aliases: ['start'])]
final class StartCommand extends Command
{
    public function __construct(
        private readonly ProviderRegistryInterface $providers,
        private readonly Naming $naming,
        private readonly GitRepoInterface $git,
        private readonly StateMachine $stateMachine,
        private readonly TaskSummarizer $summarizer,
        Store $store,
        Config $projectsLoader,
        AgentLauncherFactory $agentLaunchers,
        AgentLauncherInterface $agents,
        private readonly AnalyticsInterface $analytics = new NullAnalytics(),
    ) {
        parent::__construct($store, $projectsLoader, $agentLaunchers, $agents);
    }
    /**
     * Fallback word cap: used only when the LLM summarizer fails (see
     * TaskSummarizer). Prefer the LLM's own summary whenever it works.
     */
    public const SUMMARY_MAX_WORDS = 5;

    protected function configure(): void
    {
        $this
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project name for plain-prompt tasks')
            ->addArgument('input', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'issue URL or task prompt');
    }

    /**
     * @param array<string, ProjectConfig> $projects
     */
    private function matchIssueUrl(string $url, array $projects, ?string $project): ?IssueMatch
    {
        $candidates = null !== $project && isset($projects[$project]) ? [$projects[$project]] : array_values($projects);
        foreach ($candidates as $cfg) {
            $provider = $this->providers->get($cfg->provider);
            $ref = $provider->matchUrl($url, $cfg);
            if (null !== $ref) {
                return new IssueMatch($cfg, $provider, $ref);
            }
        }

        return null;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     */
    private function matchIssueKey(string $text, array $projects, ?string $project): ?IssueMatch
    {
        $candidates = null !== $project && isset($projects[$project]) ? [$projects[$project]] : array_values($projects);
        if (false === preg_match_all('/\b([A-Za-z][A-Za-z0-9]*)-(\d+)\b/', $text, $matches, \PREG_SET_ORDER)) {
            return null;
        }
        foreach ($matches as $m) {
            $prefix = strtoupper($m[1]);
            foreach ($candidates as $cfg) {
                if (!\in_array($cfg->provider, ['jira', 'linear'], true)) {
                    continue;
                }
                if (strtoupper($cfg->projectKey) === $prefix) {
                    return new IssueMatch($cfg, $this->providers->get($cfg->provider), strtoupper($m[0]));
                }
            }
        }

        return null;
    }

    private function branchBase(ProjectConfig $cfg, Issue $issue): string
    {
        if ('github' === $cfg->provider) {
            return $this->naming->branchName($cfg->projectKey, $issue->key);
        }

        return strtolower($issue->key);
    }

    private function createTask(Store $store, ProjectConfig $cfg, string $branch, ?Issue $issue, ?string $prompt, ?string $summary, AgentLauncherInterface $agents): Task
    {
        $lock = $store->taskLock($cfg->name, $branch);
        try {
            $worktree = $this->git->createWorktree($cfg->repoPath, $cfg->worktreesRoot, $branch, $cfg->primaryBranch);
            $task = new Task(project: $cfg->name, branch: $branch, worktreePath: $worktree, state: State::InProgress);
            $task->issue = $issue;
            $task->prompt = $prompt;
            $task->summary = null !== $prompt
                ? $summary
                    ?? implode(' ', \array_slice(preg_split('/\s+/', trim($prompt)) ?: [], 0, self::SUMMARY_MAX_WORDS))
                : null;
            $ctx = new TaskCtx(task: $task, cfg: $cfg, store: $store, agents: $agents);
            $this->stateMachine->enterState($ctx, State::InProgress);
            // The reuse path in startIssueTask() never reaches createTask(),
            // so this fires exactly once per task lifetime.
            $this->analytics->taskOpened($task);
        } finally {
            $lock->release();
        }

        return $task;
    }

    private function startIssueTask(Store $store, ProjectConfig $cfg, Issue $issue, AgentLauncherInterface $agents, OutputInterface $output): int
    {
        foreach ($store->allTasks($cfg->name) as $existing) {
            if (null !== $existing->issue && $existing->issue->key === $issue->key) {
                $output->writeln("task for {$issue->key} already exists: worktree {$existing->worktreePath} (state {$existing->state->value}) — reusing it");

                return self::SUCCESS;
            }
        }
        $base = $this->branchBase($cfg, $issue);
        $branch = $this->naming->dedupe($base, $this->git->allBranchNames($cfg->repoPath));
        $task = $this->createTask($store, $cfg, $branch, $issue, null, null, $agents);
        $ghIssue = 'github' === $cfg->provider ? $issue->key : null;
        $agents->setWorktreeDisplayName($task->worktreePath, $issue->key, $ghIssue);
        $output->writeln("started {$issue->key} ({$issue->title}) in project {$cfg->name}");
        $output->writeln("worktree: {$task->worktreePath} (branch {$branch})");
        $output->writeln("state: {$task->state->value} — task-analyst is running");

        return self::SUCCESS;
    }

    protected function doExecute(InputInterface $input, OutputInterface $output): int
    {
        $project = $input->getOption('project') ?: null;
        $projects = $this->projects();
        $store = $this->store();
        $agents = $this->agents();
        $text = trim(implode(' ', (array) $input->getArgument('input')));
        if ('' === $text) {
            throw new PabloError('usage: pablo task:start <issue-url> | pablo task:start --project <name> "<prompt>"');
        }

        if (str_starts_with($text, 'http://') || str_starts_with($text, 'https://')) {
            $matched = $this->matchIssueUrl($text, $projects, $project);
            if (null === $matched) {
                throw new PabloError("no managed project matches this issue URL: {$text} — check the issue_tracker config of your projects");
            }
            $issue = $matched->provider->getIssue($matched->ref, $matched->cfg);

            return $this->startIssueTask($store, $matched->cfg, $issue, $agents, $output);
        }

        $keyMatched = $this->matchIssueKey($text, $projects, $project);
        if (null !== $keyMatched) {
            $issue = $keyMatched->provider->getIssue($keyMatched->ref, $keyMatched->cfg);

            return $this->startIssueTask($store, $keyMatched->cfg, $issue, $agents, $output);
        }

        if (null === $project) {
            throw new PabloError('a plain-prompt task needs --project <name>; configured projects: '.(implode(', ', array_keys($projects)) ?: 'none configured'));
        }
        $cfg = $projects[$project] ?? null;
        if (null === $cfg) {
            throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
        }
        // The AI summary feeds the branch/worktree name (Naming::slugBranch
        // keeps the slug convention), falling back to the raw prompt when the
        // summarizer fails.
        $summary = $this->summarizer->summarize($cfg->repoPath, $text);
        $base = $this->naming->slugBranch($cfg->projectKey, $summary ?? $text);
        $branch = $this->naming->dedupe($base, $this->git->allBranchNames($cfg->repoPath));
        $task = $this->createTask($store, $cfg, $branch, null, $text, $summary, $agents);
        $agents->setWorktreeDisplayName($task->worktreePath, $branch);
        $output->writeln("started task in project {$cfg->name}");
        $output->writeln("worktree: {$task->worktreePath} (branch {$branch})");
        $output->writeln("state: {$task->state->value} — task-analyst is running");

        return self::SUCCESS;
    }
}
