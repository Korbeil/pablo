<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

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

    /** Prefix the RTK hook puts in front of a rewritten command. */
    public const RTK_PREFIX = 'rtk ';

    /**
     * Shell commands the commit command's `!` context injection needs on top
     * of its own allow list (fallback `echo`s in `a || b || echo …` chains).
     */
    private const COMMAND_EXTRA_SHELL = ['echo*'];

    public function __construct(private readonly AgentTemplates $templates)
    {
    }

    public function templates(): AgentTemplates
    {
        return $this->templates;
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
        $agentsDir ??= $this->templates->templatesDir();

        return self::profileFromMarkdown($this->templates->render($agentsDir, $agent, $providers), $agent);
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

    /** Prefix of the subagent files linked into ~/.claude/agents. */
    public const ID_PREFIX = 'pablo-';

    public static function agentId(string $agent): string
    {
        return self::ID_PREFIX.$agent;
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
        $files = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $id = self::agentId($name);
            $files[$id.'.md'] = $this->profile($name, $agentsDir, $providers)->agentFile($id);
        }

        return $files;
    }

    /**
     * @throws AgentTemplateError
     */
    public static function profileFromMarkdown(string $markdown, string $agent): ClaudeAgentProfile
    {
        [$front, $body] = self::splitFrontmatter($markdown, $agent);
        $rules = $front['permissions'] ?? [];
        if (!\is_array($rules)) {
            throw new AgentTemplateError("{$agent}: permissions must be a list");
        }

        $allowedTools = [];
        $deniedTools = [];
        $allowedPatterns = [];
        $deniedPatterns = [];
        foreach ($rules as $i => $rule) {
            if (!\is_array($rule)) {
                throw new AgentTemplateError("{$agent}: permission #{$i} is not a mapping");
            }
            $action = (string) ($rule['action'] ?? '');
            $resource = (string) ($rule['resource'] ?? '*');
            $effect = (string) ($rule['effect'] ?? '');
            $tools = self::ACTION_TOOLS[$action] ?? null;
            if (null === $tools) {
                throw new AgentTemplateError("{$agent}: permission action '{$action}' has no Claude Code equivalent");
            }
            if (!\in_array($effect, ['allow', 'deny'], true)) {
                throw new AgentTemplateError("{$agent}: permission effect '{$effect}' is not allow/deny");
            }
            if ('*' === $resource) {
                foreach ($tools as $tool) {
                    if ('allow' === $effect) {
                        $allowedTools[$tool] = true;
                    } else {
                        $deniedTools[$tool] = true;
                    }
                }
                continue;
            }
            if ('shell' !== $action) {
                throw new AgentTemplateError("{$agent}: only shell permissions may target a pattern ('{$action}' → '{$resource}')");
            }
            foreach (self::shellRules($resource) as $pattern) {
                if ('allow' === $effect) {
                    $allowedPatterns[$pattern] = true;
                } else {
                    $deniedPatterns[$pattern] = true;
                }
            }
        }
        // A denied tool is never offered, whatever the order of the rules.
        $tools = array_values(array_diff(array_keys($allowedTools), array_keys($deniedTools)));

        $steps = isset($front['steps']) && is_numeric($front['steps']) ? (int) $front['steps'] : null;

        return new ClaudeAgentProfile(
            description: trim((string) ($front['description'] ?? '')),
            prompt: self::withStepBudget(trim($body), $steps),
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
     * A shell pattern as Claude Code `Bash(...)` rules: raw and RTK-rewritten.
     * Claude Code globs `*` anywhere in the command, matching OpenCode's own
     * wildcard (`*acli jira workitem edit*` included).
     *
     * @return list<string>
     */
    public static function shellRules(string $pattern): array
    {
        return ['Bash('.$pattern.')', 'Bash('.self::RTK_PREFIX.$pattern.')'];
    }

    /**
     * OpenCode caps analysts with a hard `steps:` limit; Claude Code has no
     * per-agent equivalent, so the budget becomes an instruction (the 1 h
     * watcher timeout stays the hard backstop).
     */
    public static function withStepBudget(string $prompt, ?int $steps): string
    {
        if (null === $steps) {
            return $prompt;
        }

        return $prompt."\n\n## Step budget\n\n"
            ."You have a budget of {$steps} tool steps for this run. Plan your\n"
            ."investigation to fit it and, at the latest on step {$steps}, stop\n"
            ."calling tools and write the deliverable with what you have.\n";
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
        $markdown = @file_get_contents($commandPath);
        if (false === $markdown) {
            throw new AgentTemplateError("Cannot read command: {$commandPath}");
        }
        $name = basename($commandPath, '.md');
        [$front, $body] = self::splitFrontmatter($markdown, $name);

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

        $noEdits = "- **No code edits.** This command only commits what is already in\n"
            ."  the worktree: never edit or create files, except writing\n"
            ."  `.pablo-pr-body.md` for the PR body.\n";
        $body = preg_replace('/^## Hard rules\R\R/m', "## Hard rules\n\n".$noEdits, $body, 1) ?? $body;

        return $frontmatter.$body;
    }

    /**
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws AgentTemplateError
     */
    public static function splitFrontmatter(string $markdown, string $name): array
    {
        if (1 !== preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $markdown, $m)) {
            throw new AgentTemplateError("{$name}: no frontmatter");
        }
        try {
            $front = Yaml::parse($m[1]);
        } catch (ParseException $e) {
            throw new AgentTemplateError("{$name}: invalid frontmatter: ".$e->getMessage());
        }
        if (!\is_array($front)) {
            throw new AgentTemplateError("{$name}: frontmatter is not a mapping");
        }

        return [$front, $m[2]];
    }
}
