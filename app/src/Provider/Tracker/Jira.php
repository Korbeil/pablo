<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Support\Json;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Jira provider, backed by the acli CLI (Atlassian CLI).
 *
 * Jira access goes through `acli jira workitem …` (OAuth owned and cached by
 * acli itself). acli does not expose a work-item changelog, so
 * failure-signal detection relies on the poller's observed-status-transition
 * fallback (supportsSignalViaStatus = true); failureSignalEvents is [].
 */
final class Jira implements Provider
{
    private const JIRA_CALL_TIMEOUT_S = 30;

    private const FIELDS = 'summary,status';

    public function __construct(private readonly ProcessRunnerInterface $runner)
    {
    }

    public function name(): string
    {
        return 'jira';
    }

    public function supportsSignalViaStatus(): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function issueFromFields(array $data, ProjectConfig $cfg): Issue
    {
        $key = (string) $data['key'];
        $fields = $data['fields'] ?? [];
        $site = $cfg->tracker->site ?? 'jira';

        return new Issue(
            provider: $this->name(),
            key: $key,
            url: "https://{$site}/browse/{$key}",
            title: (string) ($fields['summary'] ?? ''),
            projectKey: $cfg->tracker->projectKey,
            status: $fields['status']['name'] ?? null,
        );
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        if (1 !== preg_match('#https?://[^/]+/browse/([A-Z][A-Z0-9]*-\d+)#', $url, $m)) {
            return null;
        }
        $key = $m[1];
        if (explode('-', $key)[0] !== $cfg->tracker->projectKey) {
            return null;
        }

        return $key;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        $out = $this->runner->run(
            ['acli', 'jira', 'workitem', 'view', $ref, '--json', '--fields', self::FIELDS],
            timeout: self::JIRA_CALL_TIMEOUT_S,
        );
        $data = Json::decodeArray($out, "acli jira workitem view {$ref} output");

        return $this->issueFromFields($data, $cfg);
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        $data = Json::tryDecodeArray($this->runner->run(self::statusCommand($key), timeout: self::JIRA_CALL_TIMEOUT_S));

        return \is_array($data) ? self::statusOf($data) : '?';
    }

    public function batchIssueStatus(array $issues): array
    {
        return IssueStatuses::fetch(
            $this->runner,
            $issues,
            static fn (string $key): array => self::statusCommand($key),
            self::statusOf(...),
            self::JIRA_CALL_TIMEOUT_S,
        );
    }

    /** @return list<string> */
    private static function statusCommand(string $key): array
    {
        return ['acli', 'jira', 'workitem', 'view', $key, '--json', '--fields', 'status'];
    }

    /** @param array<string, mixed> $data */
    private static function statusOf(array $data): string
    {
        $fields = $data['fields'] ?? [];

        return (string) ($fields['status']['name'] ?? '?');
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        return [];
    }
}
