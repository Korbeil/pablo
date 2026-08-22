<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;

/**
 * GitHub issues provider, backed by the gh CLI.
 */
final class Github implements Provider
{
    public const GH_CALL_TIMEOUT_S = 20;

    private const STATUS = ['OPEN' => 'To Do', 'CLOSED' => 'Done'];

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly RepoSlug $repoSlug,
        private readonly Time $time,
    ) {
    }

    public function name(): string
    {
        return 'github';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function repoSlug(ProjectConfig $cfg): string
    {
        return $this->repoSlug->for($cfg);
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (1 !== preg_match('#https?://github\.com/([^/]+)/([^/]+)/issues/(\d+)#', $url, $m)) {
            return null;
        }
        try {
            $slug = $this->repoSlug->for($cfg);
        } catch (\Throwable) {
            return null;
        }
        if (strtolower("{$m[1]}/{$m[2]}") !== strtolower($slug)) {
            return null;
        }

        return $m[3];
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        $out = $this->runner->run([
            'gh', 'issue', 'view', $ref, '--repo', $this->repoSlug->for($cfg),
            '--json', 'number,title,state,url',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);

        return new Issue(
            provider: $this->name(),
            key: (string) $data['number'],
            url: (string) $data['url'],
            title: (string) $data['title'],
            projectKey: $cfg->projectKey,
            status: self::STATUS[$data['state']] ?? $data['state'],
        );
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        $out = $this->runner->run([
            'gh', 'issue', 'list', '--repo', $this->repoSlug->for($cfg),
            '--assignee', $cfg->identity, '--state', 'all',
            '--json', 'number,title,state,url', '--limit', '100',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $issues = [];
        /** @var array<int, array<string, mixed>> $items */
        $items = json_decode($out, true);
        foreach ($items as $item) {
            $issues[] = new Issue(
                provider: $this->name(),
                key: (string) $item['number'],
                url: (string) $item['url'],
                title: (string) $item['title'],
                projectKey: $cfg->projectKey,
                status: self::STATUS[$item['state']] ?? $item['state'],
            );
        }

        return $issues;
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        $out = $this->runner->run([
            'gh', 'issue', 'view', $key, '--repo', $this->repoSlug->for($cfg), '--json', 'state',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
        $state = $data['state'];

        return self::STATUS[$state] ?? $state;
    }

    public function batchIssueStatus(array $issues): array
    {
        if ([] === $issues) {
            return [];
        }

        $commands = [];
        $keyIndex = [];
        foreach ($issues as $key => $cfg) {
            $keyIndex[] = $key;
            $commands[] = [
                'gh', 'issue', 'view', $key, '--repo', $this->repoSlug->for($cfg), '--json', 'state',
            ];
        }

        $results = $this->runner->runParallel($commands, check: false, timeout: self::GH_CALL_TIMEOUT_S);
        $statuses = [];
        foreach ($results as $i => $out) {
            $key = $keyIndex[$i];
            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($out, true, 512, \JSON_THROW_ON_ERROR);
                $state = $data['state'];
                $statuses[$key] = self::STATUS[$state] ?? $state;
            } catch (\Throwable) {
                $statuses[$key] = '?';
            }
        }

        return $statuses;
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        if (null === $task->prNumber || null === $cfg->failureSignal) {
            return [];
        }
        $out = $this->runner->run([
            'gh', 'api', 'repos/'.$this->repoSlug->for($cfg)."/issues/{$task->prNumber}/events",
            '--paginate',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $stamps = [];
        /** @var array<int, array<string, mixed>> $events */
        $events = json_decode($out, true);
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'labeled'
                && ($event['label']['name'] ?? null) === $cfg->failureSignal) {
                $stamps[] = $this->time->parseTs((string) $event['created_at']);
            }
        }
        sort($stamps);

        return $stamps;
    }

    public function cliName(): string
    {
        return 'gh';
    }

    public function authCheckCmd(): array
    {
        return ['gh', 'auth', 'status'];
    }
}
