<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Provider\Forge\Gitea\GiteaApi;
use Pablo\Support\ProcessRunnerInterface;
use Pablo\Support\RepoSlug;

/**
 * Gitea issues provider, backed by the Gitea CLI (`gt api`). The issues are
 * the repository's own, on its Gitea host (Support\RepoSlug::ref()); a key
 * is the issue's number.
 */
final class Gitea implements Provider
{
    private const STATUS = ['open' => 'To Do', 'closed' => 'Done'];

    private readonly GiteaApi $api;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly RepoSlug $repoSlug,
        private readonly Time $time,
    ) {
        $this->api = new GiteaApi($runner);
    }

    public function name(): string
    {
        return 'gitea';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    /**
     * https://<host>[/<sub-path>]/<owner>/<repo>/issues/<n>, on the
     * project's host and repository.
     */
    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (1 !== preg_match('#^https?://([^/]+)/(?:.+/)?([^/]+/[^/]+)/issues/(\d+)(?:[/?\#]|$)#', $url, $m)) {
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
        $data = $this->api->get($repo->host, self::issuePath($repo->slug, $ref));

        return new Issue(
            provider: $this->name(),
            key: (string) ($data['number'] ?? ltrim($ref, '#')),
            url: (string) ($data['html_url'] ?? ''),
            title: (string) ($data['title'] ?? ''),
            projectKey: $cfg->tracker->projectKey,
            status: self::statusOf($data),
        );
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        $repo = $this->repoSlug->ref($cfg);

        return self::statusOf($this->api->get($repo->host, self::issuePath($repo->slug, $key)));
    }

    public function batchIssueStatus(array $issues): array
    {
        return IssueStatuses::fetch(
            $this->runner,
            $issues,
            $this->statusCommand(...),
            self::statusOf(...),
            GiteaApi::TIMEOUT_S,
        );
    }

    /** @return list<string> */
    private function statusCommand(string $key, ProjectConfig $cfg): array
    {
        $repo = $this->repoSlug->ref($cfg);

        return GiteaApi::argv($repo->host, self::issuePath($repo->slug, $key));
    }

    /** The REST path of issue $key (a number, "#" allowed) of repository $slug. */
    public static function issuePath(string $slug, string $key): string
    {
        return GiteaApi::repo($slug).'/issues/'.rawurlencode(ltrim($key, '#'));
    }

    /** @param array<mixed> $data */
    private static function statusOf(array $data): string
    {
        if (null !== GiteaApi::errorOf($data)) {
            return '?';
        }
        $state = (string) ($data['state'] ?? '');

        return self::STATUS[$state] ?? $state;
    }

    /**
     * When the failure-signal label was put on the task's pull request: the
     * "label" events of its timeline (a pull request is an issue to Gitea),
     * whose body is "1" when the label was added, empty when removed.
     */
    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        if (null === $task->prNumber || null === $cfg->testing->failureSignal) {
            return [];
        }
        $repo = $this->repoSlug->ref($cfg);
        $events = $this->api->getAll($repo->host, self::issuePath($repo->slug, (string) $task->prNumber).'/timeline');
        $stamps = [];
        foreach ($events as $event) {
            if (($event['type'] ?? null) === 'label'
                && '1' === ($event['body'] ?? null)
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
