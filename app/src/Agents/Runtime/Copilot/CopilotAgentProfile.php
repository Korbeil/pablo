<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

/**
 * One PABLO agent translated to GitHub Copilot CLI: its `.agent.md` file
 * (linked into ~/.copilot/agents, so it can also be run by hand) plus the
 * launch-time tool and permission flags.
 *
 * Copilot agent files can only restrict which tools are visible (`tools:`
 * aliases); shell patterns and approvals only exist as CLI flags, so PABLO's
 * launches repeat the whole policy on the command line.
 */
final class CopilotAgentProfile
{
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
