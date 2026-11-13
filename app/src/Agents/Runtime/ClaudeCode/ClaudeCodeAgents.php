<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\ClaudeCode;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\AgentTranslation;
use Pablo\Agents\Runtime\PermissionRules;
use Pablo\Agents\Runtime\RuntimeSupport;

/**
 * Translates PABLO's agents and its commit command to Claude Code.
 *
 * The single source of truth stays the OpenCode templates (the shared
 * `permissions:` lists in opencode/agents/shared/*.frontmatter.md): each
 * agent is rendered exactly as for OpenCode, then its frontmatter is mapped
 * onto Claude Code tools and `Bash(...)` rules. Reads only — never writes.
 *
 * RTK: a user-level PreToolUse hook may rewrite `git push` into
 * `rtk git push` *before* permissions are checked, which slips past a plain
 * `Bash(git push*)` deny. Every shell rule is therefore emitted twice, raw
 * and `rtk`-prefixed.
 */
final class ClaudeCodeAgents
{
    /** OpenCode permission action => Claude Code tools it stands for. */
    public const ACTION_TOOLS = [
        'read' => ['Read'],
        'grep' => ['Grep'],
        'glob' => ['Glob'],
        'list' => ['Glob'],
        'webfetch' => ['WebFetch'],
        'shell' => ['Bash'],
        'edit' => ['Edit', 'Write', 'NotebookEdit'],
        'write' => ['Write'],
        'skill' => ['Skill'],
    ];

    /**
     * Shell commands the commit command's `!` context injection needs on top
     * of its own allow list (fallback `echo`s in `a || b || echo …` chains).
     */
    private const COMMAND_EXTRA_SHELL = ['echo*'];

    public function __construct(private readonly AgentTemplates $templates)
    {
    }

    /**
     * The agent rendered for OpenCode, translated. Providers only change the
     * prompt body, never the permissions.
     *
     * @param list<string> $providers
     *
     * @throws AgentTemplateError
     */
    public function profile(string $agent, ?string $agentsDir = null, array $providers = []): ClaudeAgentProfile
    {
        return self::profileFromMarkdown(AgentTranslation::renderAgent($this->templates, $agent, $agentsDir, $providers), $agent);
    }

    /**
     * The `--agents` file: every PABLO agent keyed by name.
     *
     * @param list<string> $providers
     *
     * @throws AgentTemplateError
     */
    public function renderAgentsJson(string $agentsDir, array $providers): string
    {
        $agents = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $agents[$name] = $this->profile($name, $agentsDir, $providers)->definition();
        }

        return json_encode($agents, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR)."\n";
    }

    /**
     * Every agent as a Claude Code subagent file, keyed by file name
     * (`pablo-<agent>.md`), for ~/.claude/agents.
     *
     * @param list<string> $providers
     *
     * @return array<string, string>
     *
     * @throws AgentTemplateError
     */
    public function renderAgentFiles(string $agentsDir, array $providers): array
    {
        return AgentTranslation::agentFiles('.md', fn (string $name, string $id): string => $this->profile($name, $agentsDir, $providers)->agentFile($id));
    }

    /**
     * @throws AgentTemplateError
     */
    public static function profileFromMarkdown(string $markdown, string $agent): ClaudeAgentProfile
    {
        [$front, $body] = RuntimeSupport::splitFrontmatter($markdown, $agent);
        $rules = PermissionRules::fromFrontmatter($front, $agent, array_keys(self::ACTION_TOOLS), 'Claude Code');

        $allowedTools = self::toolSet($rules->allowedActions);
        $deniedTools = self::toolSet($rules->deniedActions);
        $allowedPatterns = self::shellRuleSet($rules->allowedShell);
        $deniedPatterns = self::shellRuleSet($rules->deniedShell);
        // A denied tool is never offered, whatever the order of the rules.
        $tools = array_values(array_diff(array_keys($allowedTools), array_keys($deniedTools)));

        $steps = RuntimeSupport::steps($front);

        return new ClaudeAgentProfile(
            description: trim((string) ($front['description'] ?? '')),
            prompt: RuntimeSupport::withStepBudget(trim($body), $steps),
            tools: $tools,
            deniedTools: array_keys($deniedTools),
            allowedPatterns: array_keys($allowedPatterns),
            deniedPatterns: array_keys($deniedPatterns),
            // Edit-capable agents (the rebase resolver) accept edits; the
            // read-only analysts only ever get what is explicitly allowed —
            // nobody can answer a permission prompt in a headless run.
            permissionMode: isset($allowedTools['Edit']) && !isset($deniedTools['Edit']) ? 'acceptEdits' : 'dontAsk',
            steps: $steps,
        );
    }

    /**
     * @param list<string> $actions
     *
     * @return array<string, true> the Claude Code tools the actions stand for
     */
    private static function toolSet(array $actions): array
    {
        $tools = [];
        foreach ($actions as $action) {
            foreach (self::ACTION_TOOLS[$action] as $tool) {
                $tools[$tool] = true;
            }
        }

        return $tools;
    }

    /**
     * @param list<string> $patterns
     *
     * @return array<string, true>
     */
    private static function shellRuleSet(array $patterns): array
    {
        $rules = [];
        foreach ($patterns as $pattern) {
            foreach (self::shellRules($pattern) as $rule) {
                $rules[$rule] = true;
            }
        }

        return $rules;
    }

    /**
     * A shell pattern as Claude Code `Bash(...)` rules: raw and RTK-rewritten.
     * Claude Code globs `*` anywhere in the command, matching OpenCode's own
     * wildcard (`*acli jira workitem edit*` included).
     *
     * @return list<string>
     */
    public static function shellRules(string $pattern): array
    {
        return ['Bash('.$pattern.')', 'Bash('.RuntimeSupport::RTK_PREFIX.$pattern.')'];
    }

    /**
     * The `/pablo-commit-and-pr` command in Claude Code's format: same body
     * (the `!` context injection and `$ARGUMENTS` work alike), OpenCode's
     * permission map turned into `allowed-tools`. Claude Code commands cannot
     * deny a tool, so the "no edits" rule is restated in the body instead.
     *
     * @throws AgentTemplateError
     */
    public function renderCommand(string $commandPath): string
    {
        [$name, $front, $body] = AgentTranslation::readCommand($commandPath);

        $tools = [];
        $permission = \is_array($front['permission'] ?? null) ? $front['permission'] : [];
        foreach ($permission as $action => $rule) {
            if ('bash' === $action) {
                continue;
            }
            if ('allow' !== $rule) {
                continue;
            }
            $mapped = self::ACTION_TOOLS[(string) $action] ?? null;
            if (null === $mapped) {
                throw new AgentTemplateError("{$name}: permission '{$action}' has no Claude Code equivalent");
            }
            foreach ($mapped as $tool) {
                $tools[$tool] = true;
            }
        }
        // Edit is denied for the command even though Write is allowed (it
        // writes .pablo-pr-body.md): never let the Write mapping reopen Edit.
        unset($tools['Edit'], $tools['NotebookEdit']);

        $bash = \is_array($permission['bash'] ?? null) ? $permission['bash'] : [];
        $shell = [];
        foreach ($bash as $pattern => $rule) {
            if ('allow' === $rule) {
                $shell[] = (string) $pattern;
            }
        }
        foreach ([...$shell, ...self::COMMAND_EXTRA_SHELL] as $pattern) {
            foreach (self::shellRules($pattern) as $rule) {
                $tools[$rule] = true;
            }
        }

        $description = (string) ($front['description'] ?? '');
        $frontmatter = "---\n"
            .'description: '.json_encode($description, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\n"
            .'allowed-tools: '.implode(', ', array_keys($tools))."\n"
            ."---\n";

        return $frontmatter.AgentTranslation::withNoEditsRule($body, 'command');
    }
}
