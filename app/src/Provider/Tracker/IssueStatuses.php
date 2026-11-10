<?php

declare(strict_types=1);

namespace Pablo\Provider\Tracker;

use Pablo\Config\ProjectConfig;
use Pablo\Support\ProcessRunnerInterface;

/**
 * Provider::batchIssueStatus() for every tracker: one status call per issue,
 * all run in parallel. A call that fails or returns unreadable JSON maps
 * its key to '?' instead of failing the batch.
 */
final class IssueStatuses
{
    /**
     * @param array<string, ProjectConfig>                  $issues  issue key => project config
     * @param callable(string, ProjectConfig): list<string> $command the status call for one issue
     * @param callable(array<string, mixed>): string        $status  the status out of its JSON output
     *
     * @return array<string, string> key => status; failed keys mapped to '?'
     */
    public static function fetch(
        ProcessRunnerInterface $runner,
        array $issues,
        callable $command,
        callable $status,
        ?float $timeout = null,
    ): array {
        if ([] === $issues) {
            return [];
        }

        $keys = [];
        $commands = [];
        foreach ($issues as $key => $cfg) {
            $keys[] = (string) $key;
            $commands[] = $command((string) $key, $cfg);
        }

        $statuses = [];
        foreach ($runner->runParallel($commands, check: false, timeout: $timeout) as $i => $out) {
            try {
                /** @var array<string, mixed> $data */
                $data = json_decode($out, true, 512, \JSON_THROW_ON_ERROR);
                $statuses[$keys[$i]] = $status($data);
            } catch (\Throwable) {
                $statuses[$keys[$i]] = '?';
            }
        }

        return $statuses;
    }
}
