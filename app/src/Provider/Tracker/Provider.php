<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;

/**
 * Issue-tracker provider, CLI-first.
 *
 * Every provider talks to its service exclusively through that service's own
 * CLI (gh, acli, linear) — no MCP servers, no raw API calls, no stored tokens.
 * When a CLI fails, its own error and instructions are surfaced verbatim.
 */
interface Provider
{
    public function name(): string;

    /**
     * Whether this provider detects failure signals via the observed
     * tracker-status transition fallback (Jira sets this; acli carries no
     * changelog). Nominal interface method — see gotcha 5 in the migration.
     */
    public function supportsSignalViaStatus(): bool;

    public function matchUrl(string $url, ProjectConfig $cfg): ?string;

    public function getIssue(string $ref, ProjectConfig $cfg): Issue;

    /** @return array<int, Issue> */
    public function listAssigned(ProjectConfig $cfg): array;

    public function issueStatus(string $key, ProjectConfig $cfg): string;

    /**
     * Issue status for multiple keys, run in parallel.
     *
     * @param list<array{0: string, 1: ProjectConfig}> $pairs [key, cfg] tuples
     *
     * @return array<string, string> key => status; failed keys mapped to '?'
     */
    public function batchIssueStatus(array $pairs): array;

    /** @return array<int, \DateTimeImmutable> */
    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array;

    public function cliName(): string;

    /** @return array<int, string> */
    public function authCheckCmd(): array;
}
