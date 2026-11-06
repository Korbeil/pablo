<?php

declare(strict_types=1);

namespace Pablo\Tests\Agents\Runtime;

use Pablo\Agents\AgentTemplateError;
use Pablo\Agents\AgentTemplates;
use Pablo\Agents\Runtime\ClaudeCodeAgents;
use Pablo\Config\Config;
use Pablo\Config\GlobalConfig;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * The Claude Code translation of the real repo templates: every OpenCode
 * guardrail must survive it, in both its raw and RTK-rewritten form.
 */
final class ClaudeCodeAgentsTest extends TestCase
{
    private AgentTemplates $templates;
    private ClaudeCodeAgents $claude;

    protected function setUp(): void
    {
        $this->templates = new AgentTemplates(new Config(new GlobalConfig()));
        $this->claude = new ClaudeCodeAgents($this->templates);
    }

    /** @return iterable<string, array{0: string}> */
    public static function agents(): iterable
    {
        foreach (AgentTemplates::AGENT_NAMES as $name) {
            yield $name => [$name];
        }
    }

    /**
     * @return array{0: array<string, mixed>, 1: string} frontmatter, body
     */
    private static function split(string $markdown): array
    {
        if (1 !== preg_match('/\A---\R(.*?)\R---\R?(.*)\z/s', $markdown, $m)) {
            self::fail('no frontmatter');
        }
        $front = Yaml::parse($m[1]);
        self::assertIsArray($front);

        return [$front, $m[2]];
    }

    /**
     * @return list<array{action: string, resource: string, effect: string}>
     */
    private function openCodeRules(string $agent): array
    {
        [$front] = self::split($this->templates->render($this->templates->templatesDir(), $agent, Config::PROVIDERS));
        $this->assertIsArray($front['permissions']);
        $rules = [];
        foreach ($front['permissions'] as $rule) {
            $this->assertIsArray($rule);
            $rules[] = ['action' => (string) $rule['action'], 'resource' => (string) $rule['resource'], 'effect' => (string) $rule['effect']];
        }

        return $rules;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agents')]
    public function testEveryShellDenyHasItsRawAndRtkForm(string $agent): void
    {
        $profile = $this->claude->profile($agent);
        $denies = 0;
        foreach ($this->openCodeRules($agent) as $rule) {
            if ('shell' !== $rule['action'] || '*' === $rule['resource'] || 'deny' !== $rule['effect']) {
                continue;
            }
            ++$denies;
            $this->assertContains('Bash('.$rule['resource'].')', $profile->deniedPatterns, "{$agent}: raw deny for {$rule['resource']}");
            $this->assertContains('Bash(rtk '.$rule['resource'].')', $profile->deniedPatterns, "{$agent}: RTK deny for {$rule['resource']}");
        }
        $this->assertGreaterThan(0, $denies);
        $this->assertCount(2 * $denies, $profile->deniedPatterns, 'no extra shell rule beyond the template');

        // Both forms reach the command line.
        $args = $profile->cliArgs();
        foreach ($profile->deniedPatterns as $pattern) {
            $this->assertContains($pattern, $args);
        }
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('agents')]
    public function testToolLevelRulesAreMapped(string $agent): void
    {
        $profile = $this->claude->profile($agent);
        foreach ($this->openCodeRules($agent) as $rule) {
            if ('*' !== $rule['resource']) {
                continue;
            }
            foreach (ClaudeCodeAgents::ACTION_TOOLS[$rule['action']] as $tool) {
                if ('deny' === $rule['effect']) {
                    $this->assertContains($tool, $profile->deniedTools, "{$agent}: {$rule['action']} deny");
                    $this->assertNotContains($tool, $profile->tools);
                } else {
                    $this->assertContains($tool, $profile->tools, "{$agent}: {$rule['action']} allow");
                }
            }
        }
        // A Bash(...) rule inside the definition would remove Bash entirely.
        foreach ($profile->definition()['disallowedTools'] ?? [] as $tool) {
            $this->assertStringNotContainsString('(', $tool);
        }
        $this->assertContains('Bash', $profile->tools);
    }

    public function testAnalystsAreReadOnlyAndTheResolverEdits(): void
    {
        foreach (['task-analyst', 'task-feedback', 'ci-analyst', 'pr-feedback'] as $agent) {
            $profile = $this->claude->profile($agent);
            $this->assertSame('dontAsk', $profile->permissionMode, $agent);
            $this->assertContains('Edit', $profile->deniedTools, $agent);
            $this->assertContains('Write', $profile->deniedTools, $agent);
            $this->assertContains('Skill', $profile->deniedTools, $agent);
            $this->assertContains('Bash(git commit*)', $profile->deniedPatterns, $agent);
            $this->assertContains('Bash(rtk git push*)', $profile->deniedPatterns, $agent);
            $this->assertContains('Bash(*acli jira workitem edit*)', $profile->deniedPatterns, $agent);
        }

        $resolver = $this->claude->profile('rebase-conflict-resolver');
        $this->assertSame('acceptEdits', $resolver->permissionMode);
        $this->assertContains('Edit', $resolver->tools);
        // It pushes its own replay: git stays allowed, GitHub/tracker writes do not.
        $this->assertNotContains('Bash(git push*)', $resolver->deniedPatterns);
        $this->assertContains('Bash(gh pr merge*)', $resolver->deniedPatterns);
        $this->assertContains('Bash(rtk gh pr merge*)', $resolver->deniedPatterns);
    }

    public function testStepsBecomeAPromptBudget(): void
    {
        $analyst = $this->claude->profile('task-analyst');
        $this->assertSame(30, $analyst->steps);
        $this->assertStringContainsString('## Step budget', $analyst->prompt);
        $this->assertStringContainsString('budget of 30 tool steps', $analyst->prompt);

        $resolver = $this->claude->profile('rebase-conflict-resolver');
        $this->assertNull($resolver->steps);
        $this->assertStringNotContainsString('## Step budget', $resolver->prompt);
    }

    public function testAgentsJsonHoldsEveryAgentWithoutAModel(): void
    {
        $json = json_decode($this->claude->renderAgentsJson($this->templates->templatesDir(), ['github']), true);
        $this->assertIsArray($json);
        $this->assertSame(AgentTemplates::AGENT_NAMES, array_keys($json));
        foreach ($json as $name => $definition) {
            $this->assertNotSame('', $definition['description'], $name);
            $this->assertStringNotContainsString('{{', $definition['prompt'], $name);
            // The configured default_model is passed with --model at launch.
            $this->assertArrayNotHasKey('model', $definition, $name);
        }
    }

    public function testAgentFilesAreValidSubagentsNamedWithThePrefix(): void
    {
        $files = $this->claude->renderAgentFiles($this->templates->templatesDir(), ['github']);
        $this->assertSame(
            array_map(static fn (string $n): string => 'pablo-'.$n.'.md', AgentTemplates::AGENT_NAMES),
            array_keys($files),
        );
        foreach ($files as $file => $content) {
            [$front, $body] = ClaudeCodeAgents::splitFrontmatter($content, $file);
            $this->assertSame(basename($file, '.md'), $front['name'], $file);
            $this->assertNotSame('', $front['description'], $file);
            $this->assertArrayHasKey('tools', $front, $file);
            $this->assertArrayNotHasKey('model', $front, $file);
            $this->assertStringNotContainsString('{{', $body, $file);
        }
        $resolver = ClaudeCodeAgents::splitFrontmatter($files['pablo-rebase-conflict-resolver.md'], 'r')[0];
        $this->assertStringContainsString('Edit', $resolver['tools']);
        $analyst = ClaudeCodeAgents::splitFrontmatter($files['pablo-task-analyst.md'], 'a')[0];
        $this->assertStringNotContainsString('Edit', $analyst['tools']);
    }

    public function testUnknownActionIsRefused(): void
    {
        $this->expectException(AgentTemplateError::class);
        $this->expectExceptionMessage("'teleport' has no Claude Code equivalent");
        ClaudeCodeAgents::profileFromMarkdown("---\ndescription: x\npermissions:\n  - action: teleport\n    resource: \"*\"\n    effect: allow\n---\nbody\n", 'x');
    }

    public function testPatternOnANonShellActionIsRefused(): void
    {
        $this->expectException(AgentTemplateError::class);
        ClaudeCodeAgents::profileFromMarkdown("---\ndescription: x\npermissions:\n  - action: edit\n    resource: \"src/*\"\n    effect: deny\n---\nbody\n", 'x');
    }

    public function testCommitCommandKeepsItsAllowListAndContextInjection(): void
    {
        $source = AgentTemplates::commandsDirFor($this->templates->templatesDir()).'/pablo-commit-and-pr.md';
        $rendered = $this->claude->renderCommand($source);

        [$front, $body] = self::split($rendered);
        $this->assertArrayNotHasKey('permission', $front);
        $allowed = array_map('trim', explode(',', (string) $front['allowed-tools']));

        [$sourceFront] = self::split((string) file_get_contents($source));
        $this->assertIsArray($sourceFront['permission']);
        $this->assertIsArray($sourceFront['permission']['bash']);
        foreach ($sourceFront['permission']['bash'] as $pattern => $rule) {
            if ('allow' !== $rule) {
                $this->assertNotContains('Bash('.$pattern.')', $allowed, "{$pattern} is not allowed in OpenCode");
                continue;
            }
            $this->assertContains('Bash('.$pattern.')', $allowed);
            $this->assertContains('Bash(rtk '.$pattern.')', $allowed);
        }
        $this->assertContains('Bash(git commit*)', $allowed);
        $this->assertContains('Bash(gh pr create*)', $allowed);
        $this->assertContains('Write', $allowed);
        $this->assertNotContains('Edit', $allowed);

        $this->assertStringContainsString('!`pablo task:precommit-check --json 2>&1`', $body);
        $this->assertStringContainsString('$ARGUMENTS', $body);
        $this->assertStringContainsString('**No code edits.**', $body);
    }
}
