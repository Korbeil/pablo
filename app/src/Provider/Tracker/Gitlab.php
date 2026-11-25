<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Forge\Gitlab\GitlabApi;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;

/**
 * GitLab issues provider, backed by the glab CLI (`glab api`). The issues
 * are the project's own, on its GitLab host (Support\RepoSlug::ref()); a
 * key is the issue's IID.
 */
final class Gitlab implements Provider
{
    private const STATUS = ['opened' => 'To Do', 'closed' => 'Done'];

    private readonly GitlabApi $api;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly RepoSlug $repoSlug,
        private readonly Time $time,
    ) {
        $this->api = new GitlabApi($runner);
    }

    public function name(): string
    {
        return 'gitlab';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (1 !== preg_match('#^https?://([^/]+)/(.+?)/-/(?:issues|work_items)/(\d+)(?:[/?\#]|$)#', $url, $m)) {
            return null;
        }
        try {
            $ref = $this->repoSlug->ref($cfg);
        } catch (\Throwable) {
            return null;
        }
        if (strtolower($m[1]) !== strtolower($ref->host) || strtolower($m[2]) !== strtolower($ref->slug)) {
            return null;
        }

        return $m[3];
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        $repo = $this->repoSlug->ref($cfg);
        $data = $this->api->get($repo->host, $this->issuePath($repo->slug, $ref));

        return new Issue(
            provider: $this->name(),
            key: (string) ($data['iid'] ?? $ref),
            url: (string) ($data['web_url'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            projectKey: $cfg->tracker->projectKey,
            status: self::statusOf($data),
        );
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        $repo = $this->repoSlug->ref($cfg);

        return self::statusOf($this->api->get($repo->host, $this->issuePath($repo->slug, $key)));
    }

    public function batchIssueStatus(array $issues): array
    {
        return IssueStatuses::fetch(
            $this->runner,
            $issues,
            $this->statusCommand(...),
            self::statusOf(...),
            GitlabApi::TIMEOUT_S,
        );
    }

    /** @return list<string> */
    private function statusCommand(string $key, ProjectConfig $cfg): array
    {
        $repo = $this->repoSlug->ref($cfg);

        return GitlabApi::argv($repo->host, $this->issuePath($repo->slug, $key));
    }

    private function issuePath(string $slug, string $key): string
    {
        return GitlabApi::project($slug).'/issues/'.rawurlencode(ltrim($key, '#'));
    }

    /** @param array<mixed> $data */
    private static function statusOf(array $data): string
    {
        $state = (string) ($data['state'] ?? '');

        return self::STATUS[$state] ?? $state;
    }

    /**
     * When the failure-signal label was put on the task's merge request:
     * its label events, as GitHub's provider reads its PR's.
     */
    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        if (null === $task->prNumber || null === $cfg->testing->failureSignal) {
            return [];
        }
        $repo = $this->repoSlug->ref($cfg);
        $events = $this->api->getAll($repo->host, GitlabApi::project($repo->slug)."/merge_requests/{$task->prNumber}/resource_label_events");
        $stamps = [];
        foreach ($events as $event) {
            if (($event['action'] ?? null) === 'add'
                && \is_array($event['label'] ?? null)
                && ($event['label']['name'] ?? null) === $cfg->testing->failureSignal
                && \is_string($event['created_at'] ?? null)) {
                $stamps[] = $this->time->parseTs($event['created_at']);
            }
        }
        sort($stamps);

        return $stamps;
    }
}
