<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime\Copilot;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\PermissionRules;
use Pablo\Agents\Runtime\RuntimeSupport;

/**
 * Translates PABLO's agents and its commit command to GitHub Copilot CLI.
 *
 * The single source of truth stays the OpenCode templates: each agent is
 * rendered exactly as for OpenCode, then its `permissions:` are mapped onto
 * Copilot tools, `--allow-tool` / `--deny-tool` rules and the agent file's
 * `tools:` aliases. Reads only — never writes.
 *
 * Agents are named `pablo-<agent>` so they can live in ~/.copilot/agents
 * (and be run by hand) without clashing with the user's own.
 *
 * RTK: like Claude Code, every shell rule is emitted raw and `rtk`-prefixed,
 * in case a preToolUse hook rewrites the command before permissions run.
 */
final class CopilotAgents
{
    public const ID_PREFIX = 'pablo-';

    /**
     * OpenCode permission action => [agent file alias, built-in tools,
     * approval kind or null when the tool needs no approval].
     */
    public const ACTIONS = [
        'read' => ['read', ['view'], 'read'],
        'grep' => ['search', ['grep'], null],
        'glob' => ['search', ['glob'], null],
        'list' => ['search', ['glob'], null],
        'webfetch' => ['web', ['web_fetch'], 'url'],
        'shell' => ['execute', ['bash'], 'shell'],
        'edit' => ['edit', ['edit', 'create'], 'write'],
        'write' => ['edit', ['create'], 'write'],
        'skill' => ['skill', ['skill'], null],
    ];

    public function __construct(private readonly AgentTemplates $templates)
    {
    }

    public static function agentId(string $agent): string
    {
        return self::ID_PREFIX.$agent;
    }

    /**
     * @param list<string> $providers
     *
     * @throws AgentTemplateError
     */
    public function profile(string $agent, ?string $agentsDir = null, array $providers = []): CopilotAgentProfile
    {
        $agentsDir ??= $this->templates->templatesDir();

        return self::profileFromMarkdown($this->templates->render($agentsDir, $agent, $providers), $agent);
    }

    /**
     * Every agent file keyed by file name (`pablo-<agent>.agent.md`).
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
            $files[self::agentId($name).'.agent.md'] = $this->profile($name, $agentsDir, $providers)->definition();
        }

        return $files;
    }

    /**
     * @throws AgentTemplateError
     */
    public static function profileFromMarkdown(string $markdown, string $agent): CopilotAgentProfile
    {
        [$front, $body] = RuntimeSupport::splitFrontmatter($markdown, $agent);
        $rules = PermissionRules::fromFrontmatter($front, $agent, array_keys(self::ACTIONS), 'Copilot');
        if ([] !== $rules->allowedShell) {
            // Shell is either allowed whole or not at all here; a
            // pattern-only allow would need a per-command allow list.
            throw new AgentTemplateError("{$agent}: shell allow patterns have no Copilot translation yet ('{$rules->allowedShell[0]}')");
        }
        $deniedPatterns = [];
        foreach ($rules->deniedShell as $resource) {
            foreach (self::shellRules($resource, $agent) as $pattern) {
                $deniedPatterns[$pattern] = true;
            }
        }
        // A denied action is never offered, whatever the order of the rules.
        $actions = $rules->offeredActions();

        $aliases = [];
        $available = [];
        $allowed = [];
        foreach ($actions as $action) {
            [$alias, $builtins, $kind] = self::ACTIONS[$action];
            $aliases[$alias] = true;
            foreach ($builtins as $tool) {
                $available[$tool] = true;
            }
            if (null !== $kind && 'url' !== $kind) {
                $allowed[$kind] = true;
            }
        }
        $denied = [];
        foreach ($rules->deniedActions as $action) {
            $kind = self::ACTIONS[$action][2];
            if (null !== $kind && !isset($allowed[$kind])) {
                $denied[$kind] = true;
            }
        }

        $steps = RuntimeSupport::steps($front);

        return new CopilotAgentProfile(
            id: self::agentId($agent),
            description: trim((string) ($front['description'] ?? '')),
            prompt: RuntimeSupport::withStepBudget(trim($body), $steps),
            toolAliases: array_keys($aliases),
            availableTools: array_keys($available),
            allowed: array_keys($allowed),
            denied: [...array_keys($denied), ...array_keys($deniedPatterns)],
            allowAllUrls: \in_array('webfetch', $actions, true),
            steps: $steps,
        );
    }

    /**
     * An OpenCode shell glob as Copilot `shell(...)` rules. Copilot matches a
     * command prefix, so the surrounding `*` are dropped (`*acli jira
     * workitem edit*` → `acli jira workitem edit`); each prefix is emitted
     * bare and with `:*` (the command followed by arguments), raw and
     * `rtk`-prefixed.
     *
     * @return list<string>
     *
     * @throws AgentTemplateError
     */
    public static function shellRules(string $pattern, string $agent = 'agent'): array
    {
        $prefix = trim(trim($pattern), '*');
        $prefix = trim($prefix);
        if ('' === $prefix || str_contains($prefix, '*')) {
            throw new AgentTemplateError("{$agent}: shell pattern '{$pattern}' has no Copilot translation (only leading/trailing * are supported)");
        }

        $rules = [];
        foreach ([$prefix, RuntimeSupport::RTK_PREFIX.$prefix] as $command) {
            $rules[] = 'shell('.$command.')';
            $rules[] = 'shell('.$command.':*)';
        }

        return $rules;
    }

    /**
     * The `/pablo-commit-and-pr` command as a Copilot skill (SKILL.md).
     * Copilot has no `!` context injection, so those commands become a
     * "run these first" step; the model may never invoke it on its own
     * (git discipline), and "no edits" is restated in the hard rules.
     *
     * @throws AgentTemplateError
     */
    public function renderSkill(string $commandPath): string
    {
        $markdown = @file_get_contents($commandPath);
        if (false === $markdown) {
            throw new AgentTemplateError("Cannot read command: {$commandPath}");
        }
        $name = basename($commandPath, '.md');
        [$front, $body] = RuntimeSupport::splitFrontmatter($markdown, $name);

        $flags = \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE;
        $frontmatter = "---\n"
            .'name: '.$name."\n"
            .'description: '.json_encode((string) ($front['description'] ?? ''), $flags)."\n"
            ."argument-hint: \"[--force]\"\n"
            ."disable-model-invocation: true\n"
            ."---\n";

        $body = preg_replace(
            '/^## Context \(auto-injected\)\R/m',
            "## Context (gather it first)\n\nBefore anything else, run each command below from the worktree and read\nits output; the rest of this skill refers to it as \"the context\".\n",
            $body,
            1,
        ) ?? $body;
        $body = preg_replace('/^!`(.+)`$/m', '`$1`', $body) ?? $body;

        $noEdits = "- **No code edits.** This skill only commits what is already in\n"
            ."  the worktree: never edit or create files, except writing\n"
            ."  `.pablo-pr-body.md` for the PR body.\n";
        $body = preg_replace('/^## Hard rules\R\R/m', "## Hard rules\n\n".$noEdits, $body, 1) ?? $body;

        $body = str_replace(
            ['User request: $ARGUMENTS', '`$ARGUMENTS`'],
            ['User request: whatever follows `/'.$name.'` in the user\'s message.', 'the user\'s request'],
            $body,
        );

        return $frontmatter.$body;
    }
}
