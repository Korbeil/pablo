<?php

declare(strict_types=1);

namespace Pablo\Tests;

use Pablo\Config\ProjectConfig;
use Pablo\Domain\Issue;
use Pablo\Domain\Task;
use Pablo\Provider\Tracker\Provider;
use Pablo\Provider\Tracker\ProviderRegistryInterface;

/**
 * Provider registry stub returning a do-nothing provider for every name.
 */
final class StubProviders implements ProviderRegistryInterface
{
    public function get(string $name): Provider
    {
        return new DevNullProvider();
    }
}

final class DevNullProvider implements Provider
{
    public function name(): string
    {
        return 'github';
    }

    public function supportsSignalViaStatus(): bool
    {
        return false;
    }

    public function matchUrl(string $url, ProjectConfig $cfg): ?string
    {
        return null;
    }

    public function getIssue(string $ref, ProjectConfig $cfg): Issue
    {
        throw new \LogicException();
    }

    public function listAssigned(ProjectConfig $cfg): array
    {
        return [];
    }

    public function issueStatus(string $key, ProjectConfig $cfg): string
    {
        return 'todo';
    }

    public function batchIssueStatus(array $issues): array
    {
        $result = [];
        foreach ($issues as $key => $_) {
            $result[$key] = 'todo';
        }

        return $result;
    }

    public function failureSignalEvents(Task $task, ProjectConfig $cfg): array
    {
        return [];
    }

    public function cliName(): string
    {
        return 'gh';
    }

    public function authCheckCmd(): array
    {
        return [];
    }
}
