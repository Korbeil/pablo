<?php

declare(strict_types=1);

namespace Pablo\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Support\Files;

/**
 * What the runtimes that translate the OpenCode templates into their own
 * agent and command files share: the agent ids, the agent loop, reading a
 * command template and restating its "no edits" rule.
 */
final class AgentTranslation
{
    /**
     * Prefix of the agent files linked into a runtime's user directory, so
     * they never clash with the user's own agents.
     */
    public const ID_PREFIX = 'pablo-';

    public static function agentId(string $agent): string
    {
        return self::ID_PREFIX.$agent;
    }

    /**
     * The agent rendered exactly as for OpenCode, from $agentsDir (default:
     * the bundled templates). Providers only change the prompt body, never
     * the permissions.
     *
     * @param list<string> $providers
     *
     * @throws AgentTemplateError
     */
    public static function renderAgent(AgentTemplates $templates, string $agent, ?string $agentsDir, array $providers): string
    {
        return $templates->render($agentsDir ?? $templates->templatesDir(), $agent, $providers);
    }

    /**
     * Every PABLO agent's file, keyed by `pablo-<agent>` + $suffix.
     *
     * @param callable(string $agent, string $id): string $render
     *
     * @return array<string, string>
     *
     * @throws AgentTemplateError
     */
    public static function agentFiles(string $suffix, callable $render): array
    {
        $files = [];
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            $id = self::agentId($name);
            $files[$id.$suffix] = $render($name, $id);
        }

        return $files;
    }

    /**
     * A command template: its name (the file name without `.md`), its
     * frontmatter and its body.
     *
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     *
     * @throws AgentTemplateError
     */
    public static function readCommand(string $commandPath): array
    {
        $markdown = Files::tryRead($commandPath);
        if (null === $markdown) {
            throw new AgentTemplateError("Cannot read command: {$commandPath}");
        }
        $name = basename($commandPath, '.md');
        [$front, $body] = RuntimeSupport::splitFrontmatter($markdown, $name);

        return [$name, $front, $body];
    }

    /**
     * The command body for a runtime that cannot run its `!` context
     * injection: those lines become commands to run first, each still
     * between backticks, and the section says so.
     */
    public static function withContextToGather(string $body, string $what): string
    {
        $body = preg_replace(
            '/^## Context \(auto-injected\)\R/m',
            "## Context (gather it first)\n\nBefore anything else, run each command below from the worktree and read\nits output; the rest of this {$what} refers to it as \"the context\".\n",
            $body,
            1,
        ) ?? $body;

        return preg_replace('/^!`(.+)`$/m', '`$1`', $body) ?? $body;
    }

    /**
     * The command body with a "No code edits" rule first under its
     * `## Hard rules`, for runtimes whose commands cannot deny a tool.
     * $what names the translated command ("command", "skill").
     */
    public static function withNoEditsRule(string $body, string $what): string
    {
        $noEdits = "- **No code edits.** This {$what} only commits what is already in\n"
            ."  the worktree: never edit or create files, except writing\n"
            ."  `.pablo-pr-body.md` for the PR body.\n";

        return preg_replace('/^## Hard rules\R\R/m', "## Hard rules\n\n".$noEdits, $body, 1) ?? $body;
    }
}
