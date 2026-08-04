<?php

declare(strict_types=1);

namespace Pablo\Config;

/**
 * A parsed project configuration (frozen: all properties are readonly).
 */
final class ProjectConfig
{
    /**
     * @param list<string> $botWhitelist
     * @param list<string> $ciIgnoreChecks
     */
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly string $repoPath,
        public readonly string $primaryBranch,
        public readonly string $worktreesRoot,
        public readonly string $provider,
        public readonly string $identity,
        public readonly string $projectKey,
        public readonly string $syncStrategy,
        public readonly bool $syncAutoApply,
        public readonly int $syncInterval,
        public readonly int $pollInterval,
        public readonly ?string $failureSignal,
        public readonly array $botWhitelist,
        public readonly array $ciIgnoreChecks,
        public readonly ?string $site = null,
        public readonly ?string $confluenceSpace = null,
        public readonly ?string $startupScript = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'repo_path' => $this->repoPath,
            'primary_branch' => $this->primaryBranch,
            'worktrees_root' => $this->worktreesRoot,
            'provider' => $this->provider,
            'identity' => $this->identity,
            'project_key' => $this->projectKey,
            'sync_strategy' => $this->syncStrategy,
            'sync_auto_apply' => $this->syncAutoApply,
            'sync_interval' => $this->syncInterval,
            'poll_interval' => $this->pollInterval,
            'failure_signal' => $this->failureSignal,
            'bot_whitelist' => $this->botWhitelist,
            'ci_ignore_checks' => $this->ciIgnoreChecks,
            'site' => $this->site,
            'confluence_space' => $this->confluenceSpace,
            'startup_script' => $this->startupScript,
        ];
    }
}
