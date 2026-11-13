<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Support\PabloError;

/**
 * issue_tracker.*: where the project's issues live.
 */
final class TrackerConfig
{
    public function __construct(
        public readonly string $provider,
        public readonly string $identity,
        public readonly string $projectKey,
        public readonly ?string $repo = null,
        public readonly ?string $site = null,
    ) {
    }

    /**
     * @param array<string, mixed> $project
     */
    public static function fromYaml(array $project, string $file): self
    {
        $provider = (string) ConfigValues::required($project, 'issue_tracker.provider', $file);
        if (!\in_array($provider, Config::PROVIDERS, true)) {
            throw new PabloError(\sprintf('%s: unknown provider %s (expected one of %s)', $file, var_export($provider, true), '["github", "jira", "linear"]'));
        }
        $identity = (string) ConfigValues::required($project, 'issue_tracker.identity', $file);
        $projectKey = $project['issue_tracker']['project_key'] ?? null;
        if (null === $projectKey) {
            throw new PabloError(\sprintf("%s: missing required key 'issue_tracker.project_key'", $file));
        }
        $repo = $project['issue_tracker']['repo'] ?? null;

        return new self(
            provider: $provider,
            identity: $identity,
            projectKey: (string) $projectKey,
            repo: null !== $repo && '' !== $repo ? (string) $repo : null,
            site: $project['issue_tracker']['site'] ?? null,
        );
    }
}
