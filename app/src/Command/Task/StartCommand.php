<?php

declare(strict_types=1);

namespace Pablo\Command\Task;

use Pablo\Agents\AgentLauncherInterface;
use Pablo\Command\Command;
use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\State;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepo;
use Pablo\Provider\Tracker\ProviderRegistry;
use Pablo\StateMachine\StateMachine;
use Pablo\StateMachine\TaskCtx;
use Pablo\Store\Store;
use Pablo\Support\Naming;
use Pablo\Support\PabloError;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class StartCommand extends Command
{
    public const SUMMARY_MAX_WORDS = 5;

    protected function configure(): void
    {
        $this->setName('start')
            ->setDescription('start a task (issue URL or --project + prompt)')
            ->addOption('project', null, InputOption::VALUE_REQUIRED, 'project name for plain-prompt tasks')
            ->addArgument('input', InputArgument::IS_ARRAY | InputArgument::OPTIONAL, 'issue URL or task prompt');
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return array{0: ProjectConfig, 1: \Pablo\Provider\Tracker\Provider, 2: string}|null
     */
    private function matchIssueUrl(string $url, array $projects, ?string $project): ?array
    {
        $candidates = null !== $project && isset($projects[$project]) ? [$projects[$project]] : array_values($projects);
        foreach ($candidates as $cfg) {
            $provider = ProviderRegistry::get($cfg->provider);
            $ref = $provider->matchUrl($url, $cfg);
            if (null !== $ref) {
                return [$cfg, $provider, $ref];
            }
        }

        return null;
    }

    /**
     * @param array<string, ProjectConfig> $projects
     *
     * @return array{0: ProjectConfig, 1: \Pablo\Provider\Tracker\Provider, 2: string}|null
     */
    private function matchIssueKey(string $text, array $projects, ?string $project): ?array
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
                    return [$cfg, ProviderRegistry::get($cfg->provider), strtoupper($m[0])];
                }
            }
        }

        return null;
    }

    private function branchBase(ProjectConfig $cfg, Issue $issue): string
    {
        if ('github' === $cfg->provider) {
            return Naming::branchName($cfg->projectKey, $issue->key);
        }

        return strtolower($issue->key);
    }

    private function createTask(Store $store, ProjectConfig $cfg, string $branch, ?Issue $issue, ?string $prompt, AgentLauncherInterface $agents): Task
    {
        $lock = Store::taskLock($store, $cfg->name, $branch);
        try {
            $worktree = GitRepo::createWorktree($cfg->repoPath, $cfg->worktreesRoot, $branch, $cfg->primaryBranch);
            $task = new Task(project: $cfg->name, branch: $branch, worktreePath: $worktree, state: State::InProgress);
            $task->issue = $issue;
            $task->prompt = $prompt;
            $task->summary = null !== $prompt
                ? implode(' ', \array_slice(preg_split('/\s+/', trim($prompt)) ?: [], 0, self::SUMMARY_MAX_WORDS))
                : null;
            $ctx = new TaskCtx(task: $task, cfg: $cfg, store: $store, agents: $agents);
            StateMachine::enterState($ctx, State::InProgress);
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
        $branch = Naming::dedupe($base, GitRepo::allBranchNames($cfg->repoPath));
        $task = $this->createTask($store, $cfg, $branch, $issue, null, $agents);
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
            throw new PabloError('usage: pablo start <issue-url> | pablo start --project <name> "<prompt>"');
        }

        if (str_starts_with($text, 'http://') || str_starts_with($text, 'https://')) {
            $matched = $this->matchIssueUrl($text, $projects, $project);
            if (null === $matched) {
                throw new PabloError("no managed project matches this issue URL: {$text} — check the issue_tracker config of your projects");
            }
            [$cfg, $provider, $ref] = $matched;

            return $this->startIssueTask($store, $cfg, $provider->getIssue($ref, $cfg), $agents, $output);
        }

        $keyMatched = $this->matchIssueKey($text, $projects, $project);
        if (null !== $keyMatched) {
            [$cfg, $provider, $key] = $keyMatched;

            return $this->startIssueTask($store, $cfg, $provider->getIssue($key, $cfg), $agents, $output);
        }

        if (null === $project) {
            throw new PabloError('a plain-prompt task needs --project <name>; configured projects: '.(implode(', ', array_keys($projects)) ?: 'none configured'));
        }
        $cfg = $projects[$project] ?? null;
        if (null === $cfg) {
            throw new PabloError("unknown project '{$project}'; configured projects: ".implode(', ', array_keys($projects)));
        }
        $base = Naming::slugBranch($cfg->projectKey, $text);
        $branch = Naming::dedupe($base, GitRepo::allBranchNames($cfg->repoPath));
        $task = $this->createTask($store, $cfg, $branch, null, $text, $agents);
        $agents->setWorktreeDisplayName($task->worktreePath, $branch);
        $output->writeln("started task in project {$cfg->name}");
        $output->writeln("worktree: {$task->worktreePath} (branch {$branch})");
        $output->writeln("state: {$task->state->value} — task-analyst is running");

        return self::SUCCESS;
    }
}
