<?php

declare(strict_types=1);

namespace Pablo\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Analytics\AnalyticsInterface;
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

/**
 * The core of `pablo task:start`, shared by the CLI command and the
 * dashboard's new-task modal so neither surface can drift from the other.
 *
 * Starting a task is the deliberate write exception on the dashboard —
 * same shape as the Slack modal's network exception: never on page load,
 * only on explicit submit.
 */
final class TaskStarter
{
    /**
     * Fallback word cap: used only when the LLM summarizer fails (see
     * TaskSummarizer). Prefer the LLM's own summary whenever it works.
     */
    public const SUMMARY_MAX_WORDS = 5;

    public function __construct(
        private readonly ProviderRegistryInterface $providers,
        private readonly Naming $naming,
        private readonly GitRepoInterface $git,
        private readonly StateMachine $stateMachine,
        private readonly TaskSummarizer $summarizer,
        private readonly AnalyticsInterface $analytics,
        private readonly Store $store,
        private readonly Config $projectsLoader,
        private readonly AgentLauncherInterface $agents,
    ) {
    }

    /** @return array<string, ProjectConfig> */
    public function projects(): array
    {
        return $this->projectsLoader->loadProjects();
    }

    public function start(string $text, ?string $project): StartResult
    {
        $projects = $this->projects();

        if (str_starts_with($text, 'http://') || str_starts_with($text, 'https://')) {
            $matched = $this->matchIssueUrl($text, $projects, $project);
            if (null === $matched) {
                throw new PabloError("no managed project matches this issue URL: {$text} — check the issue_tracker config of your projects");
            }
            $issue = $matched->provider->getIssue($matched->ref, $matched->cfg);

            return $this->startIssueTask($matched->cfg, $issue);
        }

        $keyMatched = $this->matchIssueKey($text, $projects, $project);
        if (null !== $keyMatched) {
            $issue = $keyMatched->provider->getIssue($keyMatched->ref, $keyMatched->cfg);

            return $this->startIssueTask($keyMatched->cfg, $issue);
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
        $base = $this->prefixed($cfg, $this->naming->slugBranch($cfg->projectKey, $summary ?? $text));
        $branch = $this->naming->dedupe($base, $this->git->allBranchNames($cfg->repoPath));
        $task = $this->createTask($cfg, $branch, null, $text, $summary);
        $this->agents->setWorktreeDisplayName($task->worktreePath, $branch);

        return new StartResult(project: $cfg->name, branch: $branch, worktreePath: $task->worktreePath);
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
        $base = 'github' === $cfg->provider
            ? $this->naming->branchName($cfg->projectKey, $issue->key)
            : strtolower($issue->key);

        return $this->prefixed($cfg, $base);
    }

    /**
     * Prepends the project's branch_prefix (global or per-project override;
     * raw, no separator — the user writes "pablo/" or "pablo-" themselves).
     * null/'' means the default unprefixed naming.
     */
    private function prefixed(ProjectConfig $cfg, string $base): string
    {
        $prefix = $cfg->branchPrefix ?? '';

        return '' === $prefix ? $base : $prefix.$base;
    }

    private function createTask(ProjectConfig $cfg, string $branch, ?Issue $issue, ?string $prompt, ?string $summary): Task
    {
        $lock = $this->store->taskLock($cfg->name, $branch);
        try {
            $worktree = $this->git->createWorktree($cfg->repoPath, $cfg->worktreesRoot, $branch, $cfg->primaryBranch);
            $task = new Task(project: $cfg->name, branch: $branch, worktreePath: $worktree, state: State::InProgress);
            $task->issue = $issue;
            $task->prompt = $prompt;
            $task->summary = null !== $prompt
                ? $summary
                    ?? implode(' ', \array_slice(preg_split('/\s+/', trim($prompt)) ?: [], 0, self::SUMMARY_MAX_WORDS))
                : null;
            $ctx = new TaskCtx(task: $task, cfg: $cfg, store: $this->store, agents: $this->agents);
            $this->stateMachine->enterState($ctx, State::InProgress);
            // The reuse path in startIssueTask() never reaches createTask(),
            // so this fires exactly once per task lifetime.
            $this->analytics->taskOpened($task);
        } finally {
            $lock->release();
        }

        return $task;
    }

    private function startIssueTask(ProjectConfig $cfg, Issue $issue): StartResult
    {
        foreach ($this->store->allTasks($cfg->name) as $existing) {
            if (null !== $existing->issue && $existing->issue->key === $issue->key) {
                return new StartResult(
                    project: $cfg->name,
                    branch: $existing->branch,
                    worktreePath: $existing->worktreePath,
                    issueKey: $issue->key,
                    issueTitle: $issue->title,
                    reused: true,
                    reusedState: $existing->state->value,
                );
            }
        }
        $base = $this->branchBase($cfg, $issue);
        $branch = $this->naming->dedupe($base, $this->git->allBranchNames($cfg->repoPath));
        $task = $this->createTask($cfg, $branch, $issue, null, null);
        $ghIssue = 'github' === $cfg->provider ? $issue->key : null;
        $this->agents->setWorktreeDisplayName($task->worktreePath, $issue->key, $ghIssue);

        return new StartResult(
            project: $cfg->name,
            branch: $branch,
            worktreePath: $task->worktreePath,
            issueKey: $issue->key,
            issueTitle: $issue->title,
        );
    }
}
