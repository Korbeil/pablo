<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Support\Proc;

/**
 * Linear provider, backed by schpet's linear CLI.
 */
final class Linear implements Provider
{
    public function name(): string
    {
        return 'linear';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (1 !== preg_match('#https?://linear\.app/[^/]+/issue/([A-Z][A-Z0-9]*-\d+)#', $url, $m)) {
            return null;
        }
        $key = $m[1];
        if (explode('-', $key)[0] !== $cfg->projectKey) {
            return null;
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function view(string $key): array
    {
        $out = Proc::run(['linear', 'issue', 'view', $key, '--json']);
        /** @var array<string, mixed> $data */
        $data = json_decode($out, true);

        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function issueFromJson(array $data, ProjectConfig $cfg): Issue
    {
        return new Issue(
            provider: $this->name(),
            key: (string) $data['identifier'],
            url: (string) ($data['url'] ?? ''),
            title: (string) $data['title'],
            projectKey: $cfg->projectKey,
            status: $data['state']['name'] ?? null,
        );
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        return $this->issueFromJson($this->view($ref), $cfg);
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        $out = Proc::run(['linear', 'issue', 'list', '--assignee', $cfg->identity, '--json']);
        $issues = [];
        /** @var array<int, array<string, mixed>> $items */
        $items = json_decode($out, true);
        foreach ($items as $item) {
            $issues[] = $this->issueFromJson($item, $cfg);
        }

        return $issues;
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        $data = $this->view($key);

        return (string) $data['state']['name'];
    }

    public function batchIssueStatus(array $pairs): array
    {
        if ([] === $pairs) {
            return [];
        }

        $commands = [];
        $keyIndex = [];
        foreach ($pairs as $i => [$key]) {
            $keyIndex[$i] = $key;
            $commands[] = ['linear', 'issue', 'view', $key, '--json'];
        }

        $results = Proc::runParallel($commands, check: false);
        $statuses = [];
        foreach ($results as $i => $out) {
            $key = $keyIndex[$i];
            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($out, true, 512, \JSON_THROW_ON_ERROR);
                $statuses[$key] = (string) $data['state']['name'];
            } catch (\Throwable) {
                $statuses[$key] = '?';
            }
        }

        return $statuses;
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        if (null === $task->issue || null === $cfg->failureSignal) {
            return [];
        }
        $data = $this->view($task->issue->key);
        $stamps = [];
        foreach ($data['history'] ?? [] as $entry) {
            if (($entry['toState']['name'] ?? null) === $cfg->failureSignal) {
                $stamps[] = Proc::parseTs((string) $entry['createdAt']);
            }
        }
        sort($stamps);

        return $stamps;
    }

    public function cliName(): string
    {
        return 'linear';
    }

    public function authCheckCmd(): array
    {
        return ['linear', 'auth', 'status'];
    }
}
