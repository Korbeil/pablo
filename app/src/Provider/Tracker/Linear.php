<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Domain\Time;
use Pablo\Support\Json;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Linear provider, backed by schpet's linear CLI.
 */
final class Linear implements Provider
{
    /**
     * The linear CLI is a Deno binary making GraphQL calls to api.linear.app;
     * `issue view` returns the whole history and `issue list` may paginate,
     * so allow the same headroom as acli rather than gh's 20 s.
     */
    private const LINEAR_CALL_TIMEOUT_S = 30;

    public function __construct(
        private readonly ProcessRunnerInterface $runner,
        private readonly Time $time,
    ) {
    }

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
        if (explode('-', $key)[0] !== $cfg->tracker->projectKey) {
            return null;
        }

        return $key;
    }

    /** @return array<string, mixed> */
    private function view(string $key): array
    {
        $out = $this->runner->run(self::viewCommand($key), timeout: self::LINEAR_CALL_TIMEOUT_S);

        return Json::decodeArray($out, "linear issue view {$key} output");
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
            projectKey: $cfg->tracker->projectKey,
            status: $data['state']['name'] ?? null,
        );
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        return $this->issueFromJson($this->view($ref), $cfg);
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        $out = $this->runner->run(['linear', 'issue', 'list', '--assignee', $cfg->tracker->identity, '--json'], timeout: self::LINEAR_CALL_TIMEOUT_S);
        $issues = [];
        $items = Json::decodeList($out, 'linear issue list output');
        foreach ($items as $item) {
            $issues[] = $this->issueFromJson($item, $cfg);
        }

        return $issues;
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return self::statusOf($this->view($key));
    }

    public function batchIssueStatus(array $issues): array
    {
        return IssueStatuses::fetch(
            $this->runner,
            $issues,
            static fn (string $key): array => self::viewCommand($key),
            self::statusOf(...),
            self::LINEAR_CALL_TIMEOUT_S,
        );
    }

    /** @return list<string> */
    private static function viewCommand(string $key): array
    {
        return ['linear', 'issue', 'view', $key, '--json'];
    }

    /** @param array<string, mixed> $data */
    private static function statusOf(array $data): string
    {
        return (string) $data['state']['name'];
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        if (null === $task->issue || null === $cfg->testing->failureSignal) {
            return [];
        }
        $data = $this->view($task->issue->key);
        $stamps = [];
        foreach ($data['history'] ?? [] as $entry) {
            if (($entry['toState']['name'] ?? null) === $cfg->testing->failureSignal) {
                $stamps[] = $this->time->parseTs((string) $entry['createdAt']);
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
