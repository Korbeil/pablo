<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeAgents;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeRuntime;
use Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeSessions;
use Pablo\Agents\Runtime\Copilot\CopilotAgents;
use Pablo\Agents\Runtime\Copilot\CopilotRuntime;
use Pablo\Agents\Runtime\Copilot\CopilotSessions;
use Pablo\Agents\Runtime\OpenCode\OpenCodeRuntime;
use Pablo\Agents\Runtime\OpenCode\OpenCodeSessions;
use Pablo\Config\Config;
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
 */
final class AgentRuntimeRegistry
{
    /** In detection order: system:install falls back along it when the default CLI is missing. */
    public const NAMES = [OpenCodeRuntime::NAME, ClaudeCodeRuntime::NAME, CopilotRuntime::NAME];

    public const DEFAULT = OpenCodeRuntime::NAME;

    /** @var array<string, AgentRuntimeInterface> */
    private array $runtimes = [];

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
            return self::DEFAULT;
        }

        return self::checkName($name);
    }

    /**
     * @throws PabloError unless $name is a known runtime
     */
    public static function checkName(string $name, string $what = 'agent_runtime'): string
    {
        if (!\in_array($name, self::NAMES, true)) {
            throw new PabloError(\sprintf("unknown %s '%s' (expected one of: %s)", $what, $name, implode(', ', self::NAMES)));
        }

        return $name;
    }

    public function resolveName(): string
    {
        return self::nameFrom($this->globalConfig->defaults());
    }

    /**
     * The runtime named $name, or the selected one.
     *
     * @throws PabloError on an unknown runtime name
     */
    public function resolve(?string $name = null): AgentRuntimeInterface
    {
        $name = self::checkName($name ?? $this->resolveName());

        return $this->runtimes[$name] ??= $this->build($name);
    }

    /**
     * The selected runtime, or the default one when the configured name is
     * unknown (for checks that report the bad name themselves).
     */
    public function resolveOrDefault(): AgentRuntimeInterface
    {
        try {
            return $this->resolve();
        } catch (PabloError) {
            return $this->resolve(self::DEFAULT);
        }
    }

    /** @return list<AgentRuntimeInterface> every runtime, in NAMES order */
    public function all(): array
    {
        return array_map($this->resolve(...), self::NAMES);
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

    private function build(string $name): AgentRuntimeInterface
    {
        $templates = new AgentTemplates(new Config($this->globalConfig));

        return match ($name) {
            ClaudeCodeRuntime::NAME => new ClaudeCodeRuntime(new ClaudeCodeAgents($templates), globalConfig: $this->globalConfig),
            CopilotRuntime::NAME => new CopilotRuntime(new CopilotAgents($templates)),
            default => new OpenCodeRuntime($templates),
        };
    }
}
