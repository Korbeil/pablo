<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Provider\Git\GitRepo;
use Pablo\Support\PabloError;
use Pablo\Support\Proc;

/**
 * GitHub issues provider, backed by the gh CLI.
 */
final class Github implements Provider
{
    public const GH_CALL_TIMEOUT_S = 20;

    private const STATUS = ['OPEN' => 'To Do', 'CLOSED' => 'Done'];

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
        return self::slug($cfg);
    }

    public static function slug(ProjectConfig $cfg): string
    {
        $url = GitRepo::originUrl($cfg->repoPath);
        if (null === $url) {
            throw new PabloError("{$cfg->name}: repo at {$cfg->repoPath} has no origin remote");
        }
        if (1 !== preg_match('#github\.com[:/]([^/]+)/([^/]+?)(?:\.git)?(?:/|$)#', $url, $m)) {
            throw new PabloError("{$cfg->name}: origin remote is not a GitHub URL: {$url}");
        }

        return "{$m[1]}/{$m[2]}";
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (1 !== preg_match('#https?://github\.com/([^/]+)/([^/]+)/issues/(\d+)#', $url, $m)) {
            return null;
        }
        try {
            $slug = $this->repoSlug($cfg);
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
        $out = Proc::run([
            'gh', 'issue', 'view', $ref, '--repo', $this->repoSlug($cfg),
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
        $out = Proc::run([
            'gh', 'issue', 'list', '--repo', $this->repoSlug($cfg),
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
        $out = Proc::run([
            'gh', 'issue', 'view', $key, '--repo', $this->repoSlug($cfg), '--json', 'state',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);
        $state = $data['state'];

        return self::STATUS[$state] ?? $state;
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        if (null === $task->prNumber || null === $cfg->failureSignal) {
            return [];
        }
        $out = Proc::run([
            'gh', 'api', "repos/{$this->repoSlug($cfg)}/issues/{$task->prNumber}/events",
            '--paginate',
        ], timeout: self::GH_CALL_TIMEOUT_S);
        $stamps = [];
        /** @var array<int, array<string, mixed>> $events */
        $events = json_decode($out, true);
        foreach ($events as $event) {
            if (($event['event'] ?? null) === 'labeled'
                && ($event['label']['name'] ?? null) === $cfg->failureSignal) {
                $stamps[] = Proc::parseTs((string) $event['created_at']);
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
