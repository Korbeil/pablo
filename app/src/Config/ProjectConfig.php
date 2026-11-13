<?php

declare(strict_types=1);

namespace Pablo\Config;

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
}
