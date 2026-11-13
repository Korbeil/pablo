<?php

declare(strict_types=1);

namespace Pablo\Config;

use Pablo\Domain\State;

/**
 * A parsed project configuration (frozen: all properties are readonly),
 * grouped by YAML section; each group parses its own section (fromYaml()).
 */
final class ProjectConfig
{
    public function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly RepoConfig $repo,
        public readonly TrackerConfig $tracker,
        public readonly SyncConfig $sync,
        public readonly StatePollingConfig $statePolling,
        public readonly string $defaultModel,
        public readonly string $prDescriptionLocale,
        public readonly ReviewConfig $review = new ReviewConfig(),
        public readonly CiConfig $ci = new CiConfig(),
        public readonly TestingConfig $testing = new TestingConfig(),
        public readonly MainUpdateConfig $mainUpdate = new MainUpdateConfig(),
        public readonly HooksConfig $hooks = new HooksConfig(),
        public readonly ?string $branchPrefix = null,
        public readonly ?string $confluenceSpace = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'repo_path' => $this->repo->path,
            'primary_branch' => $this->repo->primaryBranch,
            'primary_remote' => $this->repo->primaryRemote,
            'worktrees_root' => $this->repo->worktreesRoot,
            'provider' => $this->tracker->provider,
            'identity' => $this->tracker->identity,
            'project_key' => $this->tracker->projectKey,
            'issue_repo' => $this->tracker->repo,
            'sync_strategy' => $this->sync->strategy,
            'sync_auto_apply' => $this->sync->autoApply,
            'sync_interval' => $this->sync->intervalMinutes,
            'poll_interval' => $this->statePolling->intervalMinutes,
            'main_update_enabled' => $this->mainUpdate->enabled,
            'main_update_interval' => $this->mainUpdate->intervalMinutes,
            'failure_signal' => $this->testing->failureSignal,
            'testing_enabled' => $this->testing->enabled,
            'bot_whitelist' => $this->review->botWhitelist,
            'ci_ignore_checks' => $this->ci->ignoreChecks,
            'site' => $this->tracker->site,
            'confluence_space' => $this->confluenceSpace,
            'startup_script' => $this->repo->startupScript,
            'default_model' => $this->defaultModel,
            'pr_description_locale' => $this->prDescriptionLocale,
            'branch_prefix' => $this->branchPrefix,
            'hooks' => [
                'command' => $this->hooks->command,
                'states' => null !== $this->hooks->states ? array_map(static fn (State $s): string => $s->value, $this->hooks->states) : null,
                'agent_finished' => $this->hooks->agentFinished,
            ],
        ];
    }
}
