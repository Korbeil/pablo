<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\OpenCode;

use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Agents\Runtime\RuntimeSupport;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use Pablo\Doctor\CliProbe;
use Pablo\Support\ProcessRunnerInterface;

/**
 * OpenCode: `opencode run --agent <a> '<prompt>'`, agents installed as
 * symlinked .md files under ~/.config/opencode/agents. The default runtime.
 */
final class OpenCodeRuntime implements AgentRuntimeInterface
{
    public const NAME = 'opencode';

    private ?OpenCodeAgentFiles $agentFiles = null;

    /** @param AgentTemplates|null $templates null builds one from the global config on first use */
    public function __construct(private readonly ?AgentTemplates $templates = null)
    {
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'OpenCode';
    }

    public function binary(): string
    {
        return 'opencode';
    }

    public function defaultModel(): string
    {
        return 'opencode-go/glm-5.3-flash';
    }

    public function newSessionId(): ?string
    {
        return null;
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null): string
    {
        // OpenCode v2 removed `run --dir` and the TUI's positional directory
        // + --prompt flags: callers cd into the worktree first.
        return 'opencode run'
            .(null !== $model ? ' --model '.escapeshellarg($model) : '')
            .' --agent '.RuntimeSupport::word($agent).' '.escapeshellarg($prompt);
    }

    public function modelError(string $model): ?string
    {
        return null;
    }

    public function sessions(): RuntimeSessionsInterface
    {
        return new OpenCodeSessions();
    }

    public function agentFiles(): RuntimeAgentFilesInterface
    {
        return $this->agentFiles ??= new OpenCodeAgentFiles($this->templates ?? new AgentTemplates(new Config(new GlobalConfig())));
    }

    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface
    {
        return new OpenCodeUsage($runner);
    }

    public function oneShot(): RuntimeOneShotInterface
    {
        return new OpenCodeOneShot();
    }

    public function probe(): CliProbe
    {
        return new CliProbe(
            cli: 'opencode',
            argv: ['opencode', '--version'],
            hint: 'install opencode: https://opencode.ai',
            installCommands: [
                'Darwin' => 'curl -fsSL https://opencode.ai/install | bash',
                'Linux' => 'curl -fsSL https://opencode.ai/install | bash',
            ],
            docsUrl: 'https://opencode.ai',
        );
    }
}
