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
        /**
         * Per-agent models (agent label => model), each overriding
         * $defaultModel for that agent: the project's own `models`, over its
         * type's entry in config.yaml's `models` (left out when the project
         * sets its own default_model).
         *
         * @var array<string, string>
         */
        public readonly array $agentModels = [],
        public readonly YoloConfig $yolo = new YoloConfig(),
    ) {
    }

    /** The model $agent (an agent label) runs on. */
    public function modelFor(string $agent): string
    {
        return $this->agentModels[$agent] ?? $this->defaultModel;
    }
}
