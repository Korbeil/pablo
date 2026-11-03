<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplates;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Support\PabloError;

/**
 * Picks the agent runtime: PABLO_AGENT_RUNTIME, then `agent_runtime` in
 * ~/.pablo/config.yaml, then OpenCode. Resolved on every call — never at
 * container compile time (the compiled container is cached).
 */
final class AgentRuntimeResolver
{
    public function __construct(private readonly GlobalConfig $globalConfig = new GlobalConfig())
    {
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
        $env = getenv('PABLO_AGENT_RUNTIME');
        $name = (false !== $env && '' !== $env) ? $env : ($defaults['agent_runtime'] ?? null);
        if (!\is_string($name) || '' === $name) {
            return AgentRuntimeInterface::OPENCODE;
        }
        if (!\in_array($name, AgentRuntimeInterface::NAMES, true)) {
            throw new PabloError(\sprintf("unknown agent_runtime '%s' (expected one of: %s)", $name, implode(', ', AgentRuntimeInterface::NAMES)));
        }

        return $name;
    }

    public function resolveName(): string
    {
        return self::nameFrom($this->globalConfig->defaults());
    }

    public function resolve(?string $name = null): AgentRuntimeInterface
    {
        $name ??= $this->resolveName();

        return match ($name) {
            AgentRuntimeInterface::CLAUDE_CODE => new ClaudeCodeRuntime(
                new ClaudeCodeAgents(new AgentTemplates(new Config($this->globalConfig))),
            ),
            AgentRuntimeInterface::COPILOT => new CopilotRuntime(
                new CopilotAgents(new AgentTemplates(new Config($this->globalConfig))),
            ),
            AgentRuntimeInterface::OPENCODE => new OpenCodeRuntime(),
            default => throw new PabloError(\sprintf("unknown agent_runtime '%s' (expected one of: %s)", $name, implode(', ', AgentRuntimeInterface::NAMES))),
        };
    }
}
