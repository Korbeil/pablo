<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\NamedRegistry;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions;
use Pablo\Agents\Runtime\Copilot\CopilotAgents;
use Pablo\Agents\Runtime\Copilot\CopilotRuntime;
use Pablo\Agents\Runtime\Copilot\CopilotSessions;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Agents\Runtime\OpenCode\OpenCodeSessions;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;

/**
 * Every agent runtime PABLO knows — the one place that lists them — and the
 * selected one: PABLO_AGENT_RUNTIME, then `agent_runtime` in
 * ~/.pablo/config.yaml, then the default (OpenCode). Resolved on every call,
 * never at container compile time (the compiled container is cached).
 *
 * Adding a runtime: implement AgentRuntimeInterface (and its role
 * interfaces) in its own folder, then list it in NAMES, build() and
 * sessionStrategies().
 *
 * @extends NamedRegistry<AgentRuntimeInterface>
 */
final class AgentRuntimeRegistry extends NamedRegistry
{
    public const string ENV = 'PABLO_AGENT_RUNTIME';

    public const string CONFIG_KEY = 'agent_runtime';

    /** In detection order: system:install falls back along it when the default CLI is missing. */
    public const array NAMES = [OpenCodeRuntime::NAME, ClaudeCodeRuntime::NAME, CopilotRuntime::NAME];

    public const string DEFAULT = OpenCodeRuntime::NAME;

    /** @param AgentTemplates $templates the shared render every runtime starts from */
    public function __construct(GlobalConfig $globalConfig, private readonly AgentTemplates $templates)
    {
        parent::__construct($globalConfig);
    }

    /**
     * The runtime name from the env override or the given global defaults.
     *
     * @param array<string, mixed> $defaults GlobalConfig::defaults()
     *
     * @throws PabloError on an unknown runtime name
     */
    public static function nameFrom(array $defaults): string
    {
        $name = self::configuredName($defaults);

        return null === $name ? self::DEFAULT : self::checkName($name);
    }

    /** Checked, unlike the ADE's: an unknown runtime name throws. */
    public function resolveName(): string
    {
        return self::nameFrom($this->globalConfig->defaults());
    }

    /**
     * Session handle strategies of every runtime, the default's last: it
     * also takes the handles nobody claims (see SessionRef).
     *
     * @return non-empty-list<RuntimeSessionsInterface>
     */
    public static function sessionStrategies(): array
    {
        return [new CopilotSessions(), new ClaudeCodeSessions(), new OpenCodeSessions()];
    }

    protected function build(string $name): AgentRuntimeInterface
    {
        return match ($name) {
            ClaudeCodeRuntime::NAME => new ClaudeCodeRuntime(new ClaudeCodeAgents($this->templates), globalConfig: $this->globalConfig),
            CopilotRuntime::NAME => new CopilotRuntime(new CopilotAgents($this->templates)),
            default => new OpenCodeRuntime($this->templates),
        };
    }
}
