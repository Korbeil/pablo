<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

use Pablo\Agents\Runtime\AgentRuntimeInterface;
use Pablo\Agents\Runtime\AgentTranslation;
use Pablo\Agents\Runtime\FollowUpPolicy;
use Pablo\Agents\Runtime\RuntimeAgentFilesInterface;
use Pablo\Agents\Runtime\RuntimeOneShotInterface;
use Pablo\Agents\Runtime\RuntimeSessionsInterface;
use Pablo\Agents\Runtime\RuntimeSupport;
use Pablo\Analytics\UsageHarvesterInterface;
use Pablo\Doctor\CliProbe;
use Pablo\Support\PabloPaths;
use Pablo\Support\ProcessRunnerInterface;

/**
 * GitHub Copilot CLI: `copilot -p` with PABLO's agents installed as
 * symlinked `pablo-<agent>.agent.md` files under ~/.copilot/agents (so the
 * user can also pick them in their own sessions).
 *
 * Every launch repeats the agent's policy as CLI flags (the exact built-in
 * tool set, which also hides the user's MCP tools; pre-approved kinds; shell
 * denies), turns off the built-in MCP servers and the ask-user tool (nobody
 * answers a headless run), and gets a PABLO-assigned session id so analytics
 * and `copilot --resume` find it.
 */
final class CopilotRuntime implements AgentRuntimeInterface
{
    public const NAME = 'copilot';

    /** Claude Code model aliases, which Copilot does not know. */
    private const CLAUDE_ALIASES = ['sonnet', 'opus', 'haiku'];

    private ?CopilotAgentFiles $agentFiles = null;

    public function __construct(private readonly CopilotAgents $agents)
    {
    }

    /**
     * The user's Copilot directory (COPILOT_HOME, else ~/.copilot): agents,
     * skills and session state. Resolved per call.
     */
    public static function userConfigDir(): string
    {
        $env = getenv('COPILOT_HOME');
        if (false !== $env && '' !== $env) {
            return $env;
        }

        return PabloPaths::home().'/.copilot';
    }

    /** ~/.pablo/runtime/copilot: agents/ and skills/ (the commands as skills). */
    public static function defaultDir(): string
    {
        return PabloPaths::runtimeDir().'/copilot';
    }

    public function name(): string
    {
        return self::NAME;
    }

    public function label(): string
    {
        return 'Copilot CLI';
    }

    public function binary(): string
    {
        return 'copilot';
    }

    public function defaultModel(): string
    {
        return 'claude-sonnet-5.5';
    }

    public function newSessionId(): string
    {
        return RuntimeSupport::uuid();
    }

    public function launchCommand(string $agent, string $prompt, ?string $model, ?string $sessionId = null, ?string $detachedIn = null): string
    {
        $argv = ['copilot', '--agent', AgentTranslation::agentId($agent)];
        if (null !== $model) {
            $argv[] = '--model';
            $argv[] = $model;
        }
        if (null !== $sessionId) {
            $argv[] = '--session-id';
            $argv[] = $sessionId;
        }
        $argv = [
            ...$argv,
            '--no-ask-user', '--no-auto-update', '--no-color', '--disable-builtin-mcps',
            ...$this->agents->profile($agent)->cliArgs(),
            // The prompt goes last, as the value of -p.
            '-p', $prompt,
        ];

        return RuntimeSupport::commandLine($argv);
    }

    public function interactiveCommand(string $agent, string $prompt, ?string $model): ?string
    {
        return null;
    }

    /**
     * `copilot --resume=<session> -p` as Copilot's default agent, every tool
     * allowed but FollowUpPolicy (`--deny-tool` always wins in Copilot), no
     * ask-user tool: nobody answers a YOLO run.
     */
    public function followUpCommand(string $sessionId, string $prompt, ?string $model): string
    {
        $argv = ['copilot', '--resume='.$sessionId];
        if (null !== $model) {
            $argv[] = '--model';
            $argv[] = $model;
        }
        $argv = [...$argv, '--no-ask-user', '--no-auto-update', '--no-color', '--disable-builtin-mcps', '--allow-all-tools'];
        foreach (FollowUpPolicy::DENIED_SHELL as $pattern) {
            foreach (CopilotAgents::shellRules($pattern, 'yolo follow-up') as $rule) {
                $argv[] = '--deny-tool';
                $argv[] = $rule;
            }
        }
        // The prompt goes last, as the value of -p.
        $argv[] = '-p';
        $argv[] = $prompt;

        return RuntimeSupport::commandLine($argv);
    }

    public function modelError(string $model): ?string
    {
        if (str_contains($model, '/')) {
            return \sprintf(
                "'%s' looks like an OpenCode provider/model id; agent_runtime copilot takes a Copilot model id such as claude-sonnet-5.5 or gpt-5.3-codex (bring-your-own-model ids are not supported)",
                $model,
            );
        }
        if (\in_array($model, self::CLAUDE_ALIASES, true) || 1 === preg_match('/^claude-[a-z]+-\d+-\d+$/', $model)) {
            return \sprintf(
                "'%s' is a Claude Code model name; agent_runtime copilot spells model ids with dots, e.g. claude-sonnet-5.5",
                $model,
            );
        }

        return null;
    }

    public function sessions(): RuntimeSessionsInterface
    {
        return new CopilotSessions();
    }

    public function agentFiles(): RuntimeAgentFilesInterface
    {
        return $this->agentFiles ??= new CopilotAgentFiles($this->agents);
    }

    public function usage(ProcessRunnerInterface $runner): UsageHarvesterInterface
    {
        return new CopilotUsage();
    }

    public function oneShot(): RuntimeOneShotInterface
    {
        return new CopilotOneShot();
    }

    public function probe(): CliProbe
    {
        return new CliProbe(
            cli: 'copilot',
            // Copilot has no non-interactive login check: --version only proves
            // the install; `copilot login` is documented as the next step.
            argv: ['copilot', '--version'],
            hint: 'reinstall: npm install -g @github/copilot, then run: copilot login',
            installCommands: [
                'Darwin' => 'npm install -g @github/copilot',
                'Linux' => 'npm install -g @github/copilot',
            ],
            docsUrl: 'https://docs.github.com/en/copilot/how-tos/copilot-cli',
        );
    }
}
