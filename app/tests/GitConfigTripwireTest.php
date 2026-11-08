<?php

declare(strict_types=1);

namespace Pablo\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Tripwire: PABLO commands/agents/engine must never set a Git identity.
 * Commits always use whatever user.name/user.email the user configured
 * themselves; an agent improvising one (e.g. "git config user.email
 * bot@example.com") pollutes every worktree of the repo. Scans agent
 * markdown (commands + templates) for identity-writing invocations and
 * src/ for config-array writes; read-only lookups stay allowed.
 */
final class GitConfigTripwireTest extends TestCase
{
    private const FORBIDDEN_MARKDOWN = [
        'inline config write (single dash flags)' => '/git\s+-\S+(?:\s+-\S+)*\s+config\s+[^|\n;]*?user\.(?:name|email)\s+["\'][^"\']*["\']/i',
        'inline config write (long flags)' => '/git\s+--\S+(?:\s+--\S+)*\s+config\s+[^|\n;]*?user\.(?:name|email)\s+["\'][^"\']*["\']/i',
        'bare config write (quoted value)' => '/git\s+config\s+(?:[^|\n;]*?\s)?user\.(?:name|email)\s+["\'][^"\']+["\']/i',
        'bare config write (unquoted value)' => '/git\s+config\s+(?:[^|\n;]*?\s)?user\.(?:name|email)\s+[A-Za-z0-9._@][^\s"|;]*/i',
        '-c identity override with value' => '/-c\s+user\.(?:name|email)=[A-Za-z0-9."\'_@]/i',
        'author/committer env assignment' => '/GIT_(?:AUTHOR|COMMITTER)_(?:NAME|EMAIL)=/i',
    ];

    private const FORBIDDEN_PHP = [
        'config array write (local)' => '/\[\'config\'\s*,\s*\'user\.(?:name|email)\'\s*,/',
        'config array write (with scope flag)' => '/\[\'config\'\s*,\s*\'--(?:local|global|system)\'\s*,\s*(?:\'[^\']+\')\s*,\s*\'user\.(?:name|email)\'\s*,/',
    ];

    public function testAgentMarkdownContainsNoIdentityWrites(): void
    {
        $this->assertNoMatches(self::FORBIDDEN_MARKDOWN, $this->markdownFiles());
    }

    /**
     * The Claude Code renders (agents.json, the claude/ command copies) are
     * generated, so they are checked from a fresh in-memory render rather
     * than from files that may not exist yet.
     */
    public function testClaudeCodeRendersContainNoIdentityWrites(): void
    {
        $templates = new \Pablo\Agents\AgentTemplates(new \Pablo\Config\Config(new \Pablo\Config\GlobalConfig()));
        $claude = new \Pablo\Agents\Runtime\ClaudeCode\ClaudeCodeAgents($templates);
        $renders = ['agents.json' => $claude->renderAgentsJson($templates->templatesDir(), \Pablo\Config\Config::PROVIDERS)];
        foreach (glob(\Pablo\Agents\AgentTemplates::commandsDirFor($templates->templatesDir()).'/*.md') ?: [] as $source) {
            $renders['claude/commands/'.basename($source)] = $claude->renderCommand($source);
        }
        foreach ($claude->renderAgentFiles($templates->templatesDir(), \Pablo\Config\Config::PROVIDERS) as $file => $content) {
            $renders['claude/agents/'.$file] = $content;
        }

        $violations = [];
        foreach ($renders as $label => $content) {
            // agents.json escapes quotes and newlines: scan the decoded text.
            $decoded = json_decode($content, true);
            $text = \is_array($decoded) ? implode("\n", array_map(static fn (array $a): string => (string) $a['prompt'], $decoded)) : $content;
            foreach (self::FORBIDDEN_MARKDOWN as $rule => $regex) {
                if (preg_match($regex, $text, $m)) {
                    $violations[] = "{$label} ({$rule}): {$m[0]}";
                }
            }
        }

        $this->assertSame([], $violations, "Git identity writes found:\n".implode("\n", $violations));
    }

    /** Same check for the Copilot renders (agent files and the commit skill). */
    public function testCopilotRendersContainNoIdentityWrites(): void
    {
        $templates = new \Pablo\Agents\AgentTemplates(new \Pablo\Config\Config(new \Pablo\Config\GlobalConfig()));
        $copilot = new \Pablo\Agents\Runtime\Copilot\CopilotAgents($templates);
        $renders = $copilot->renderAgentFiles($templates->templatesDir(), \Pablo\Config\Config::PROVIDERS);
        foreach (glob(\Pablo\Agents\AgentTemplates::commandsDirFor($templates->templatesDir()).'/*.md') ?: [] as $source) {
            $renders['copilot/skills/'.basename($source, '.md')] = $copilot->renderSkill($source);
        }

        $violations = [];
        foreach ($renders as $label => $content) {
            foreach (self::FORBIDDEN_MARKDOWN as $rule => $regex) {
                if (preg_match($regex, $content, $m)) {
                    $violations[] = "{$label} ({$rule}): {$m[0]}";
                }
            }
        }

        $this->assertSame([], $violations, "Git identity writes found:\n".implode("\n", $violations));
    }

    public function testSrcContainsNoIdentityConfigWrites(): void
    {
        $this->assertNoMatches(self::FORBIDDEN_PHP, $this->phpFiles());
    }

    /**
     * @param array<string, string> $forbidden pattern label => regex
     * @param list<string>          $paths
     */
    private function assertNoMatches(array $forbidden, array $paths): void
    {
        $violations = [];
        foreach ($paths as $path) {
            $content = (string) file_get_contents($path);
            foreach ($forbidden as $label => $regex) {
                if (preg_match($regex, $content)) {
                    preg_match($regex, $content, $m);
                    $violations[] = basename($path)." ({$label}): {$m[0]}";
                }
            }
        }

        $this->assertSame([], $violations, "Git identity writes found:\n".implode("\n", $violations));
    }

    /** @return list<string> */
    private function markdownFiles(): array
    {
        $root = \dirname(__DIR__, 2).'/opencode';

        return array_values(array_unique(array_merge(
            glob($root.'/*.md*') ?: [],
            glob($root.'/**/*.md*') ?: [],
        )));
    }

    /** @return list<string> */
    private function phpFiles(): array
    {
        return array_values(array_filter(array_merge(
            glob(\dirname(__DIR__).'/src/*.php') ?: [],
            glob(\dirname(__DIR__).'/src/**/*.php') ?: [],
        )));
    }
}
