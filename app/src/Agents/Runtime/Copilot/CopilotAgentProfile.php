<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

/**
 * One PABLO agent translated to GitHub Copilot CLI: its `.agent.md` file
 * (linked into ~/.copilot/agents, so it can also be run by hand), the first
 * message PABLO's launches send instead (inlinePrompt()) plus the
 * launch-time tool and permission flags.
 *
 * Copilot agent files can only restrict which tools are visible (`tools:`
 * aliases); shell patterns and approvals only exist as CLI flags, so PABLO's
 * launches repeat the whole policy on the command line.
 */
final class CopilotAgentProfile
{
    /** Separates the agent's instructions from the task in inlinePrompt(). */
    public const TASK_HEADING = "# Your task (PABLO)\n\n";

    /**
     * @param list<string> $toolAliases    agent file `tools:` (read, search, execute, web, edit)
     * @param list<string> $availableTools built-in tools offered at launch (`--available-tools`)
     * @param list<string> $allowed        `--allow-tool` rules (kinds such as `shell`, `write`)
     * @param list<string> $denied         `--deny-tool` rules, raw + `rtk` forms for shell
     */
    public function __construct(
        public readonly string $id,
        public readonly string $description,
        public readonly string $prompt,
        public readonly array $toolAliases,
        public readonly array $availableTools,
        public readonly array $allowed,
        public readonly array $denied,
        public readonly bool $allowAllUrls,
        public readonly ?int $steps,
    ) {
    }

    /** The `<id>.agent.md` file. No `model:` line: it would beat `--model`. */
    public function definition(): string
    {
        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR;

        return "---\n"
            .'name: '.$this->id."\n"
            .'description: '.json_encode($this->description, $flags)."\n"
            .'tools: '.json_encode($this->toolAliases, $flags)."\n"
            ."---\n\n"
            .$this->prompt."\n";
    }

    /**
     * The first message of a PABLO launch: the agent's instructions, then
     * $task under TASK_HEADING. PABLO never selects the agent with `--agent`:
     * Copilot saves a selected agent with the session and restores it on
     * `--resume`, so a YOLO follow-up would run as the read-only analyst
     * again, and in the TUI the user would have to deselect it before
     * implementing. Without it, the session's agent is Copilot's default
     * one, and the agent's limits are this run's flags only.
     *
     * $planMode: the run starts in Copilot's plan mode (interactiveCommand()),
     * where accepting the plan means implementing it.
     */
    public function inlinePrompt(string $task, bool $planMode = false): string
    {
        $intro = "You are PABLO's {$this->id} agent".('' !== $this->description ? ': '.$this->description : '.')."\n"
            ."Follow these instructions for this session.\n";
        if ($planMode) {
            $intro .= "\nYou start in Copilot's plan mode, which blocks every change to the\n"
                ."workspace: do the analysis these instructions describe and present it\n"
                ."as your plan. When the user accepts the plan, they are asking you to\n"
                ."implement it: from then on the read-only rules below no longer apply,\n"
                ."but never `git commit` or `git push` unless the user asks for it.\n";
        }

        return $intro."\n".$this->prompt."\n\n".self::TASK_HEADING.$task;
    }

    /**
     * The task inside a first message built by inlinePrompt(), or the whole
     * message when it is not one: what PABLO fingerprints for usage.
     */
    public static function taskOf(string $message): string
    {
        $at = strpos($message, "\n\n".self::TASK_HEADING);

        return false === $at ? $message : substr($message, $at + \strlen("\n\n".self::TASK_HEADING));
    }

    /**
     * Flags of the plan-mode TUI (interactiveCommand()): what the agent may
     * do without asking and its shell denies. Not the tool set nor the denied
     * kinds (`write`…): they would last the whole run and block the
     * implementation the user accepts; plan mode itself blocks every
     * workspace change until then.
     *
     * @return list<string>
     */
    public function planModeArgs(): array
    {
        $args = [];
        foreach ($this->allowed as $rule) {
            $args[] = '--allow-tool';
            $args[] = $rule;
        }
        if ($this->allowAllUrls) {
            $args[] = '--allow-all-urls';
        }
        foreach ($this->denied as $rule) {
            if (str_starts_with($rule, 'shell(')) {
                $args[] = '--deny-tool';
                $args[] = $rule;
            }
        }

        return $args;
    }

    /**
     * Launch flags enforcing the agent's rules: the exact built-in tool set
     * (which also hides every MCP tool), what is pre-approved and what is
     * denied — deny always wins over allow in Copilot.
     *
     * @return list<string>
     */
    public function cliArgs(): array
    {
        $args = ['--available-tools', implode(',', $this->availableTools)];
        foreach ($this->allowed as $rule) {
            $args[] = '--allow-tool';
            $args[] = $rule;
        }
        if ($this->allowAllUrls) {
            $args[] = '--allow-all-urls';
        }
        foreach ($this->denied as $rule) {
            $args[] = '--deny-tool';
            $args[] = $rule;
        }

        return $args;
    }
}
