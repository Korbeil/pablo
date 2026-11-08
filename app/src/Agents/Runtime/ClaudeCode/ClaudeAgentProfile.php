<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

/**
 * One PABLO agent translated to Claude Code: its definition for `--agents`
 * plus the launch-time permission flags.
 *
 * Shell-pattern rules only ever travel as CLI flags: a `Bash(...)` entry in
 * an agent definition's `disallowedTools` removes the whole Bash tool, so
 * the definition itself only carries tool-level allow/deny lists.
 */
final class ClaudeAgentProfile
{
    /**
     * @param list<string> $tools           tools the agent may use (definition `tools` + `--allowedTools`)
     * @param list<string> $deniedTools     whole tools denied (definition `disallowedTools`)
     * @param list<string> $allowedPatterns `Bash(...)` rules allowed, raw + `rtk` forms
     * @param list<string> $deniedPatterns  `Bash(...)` rules denied, raw + `rtk` forms
     */
    public function __construct(
        public readonly string $description,
        public readonly string $prompt,
        public readonly array $tools,
        public readonly array $deniedTools,
        public readonly array $allowedPatterns,
        public readonly array $deniedPatterns,
        public readonly string $permissionMode,
        public readonly ?int $steps,
    ) {
    }

    /** @return array<string, mixed> the `--agents` JSON entry */
    public function definition(): array
    {
        $definition = [
            'description' => $this->description,
            'prompt' => $this->prompt,
            'tools' => $this->tools,
        ];
        if ([] !== $this->deniedTools) {
            $definition['disallowedTools'] = $this->deniedTools;
        }

        return $definition;
    }

    /**
     * The same agent as a Claude Code subagent file (~/.claude/agents/<id>.md),
     * so it shows in `/agents` and the `@` picker. Only tool-level limits fit
     * in a file: shell-pattern rules stay launch flags (see cliArgs()), so
     * outside a PABLO launch the agent is bound by its tool lists alone.
     */
    public function agentFile(string $id): string
    {
        $front = "---\n"
            .'name: '.$id."\n"
            .'description: '.json_encode($this->description, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
        if ([] !== $this->tools) {
            $front .= 'tools: '.implode(', ', $this->tools)."\n";
        }
        if ([] !== $this->deniedTools) {
            $front .= 'disallowedTools: '.implode(', ', $this->deniedTools)."\n";
        }

        return $front."---\n".$this->prompt."\n";
    }

    /**
     * Launch flags enforcing the same rules from the command line — the only
     * place shell patterns can be expressed.
     *
     * @return list<string>
     */
    public function cliArgs(): array
    {
        $args = ['--permission-mode', $this->permissionMode];
        $allowed = [...$this->tools, ...$this->allowedPatterns];
        if ([] !== $allowed) {
            $args = [...$args, '--allowedTools', ...$allowed];
        }
        $denied = [...$this->deniedTools, ...$this->deniedPatterns];
        if ([] !== $denied) {
            $args = [...$args, '--disallowedTools', ...$denied];
        }

        return $args;
    }
}
