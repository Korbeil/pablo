<?php

declare(strict_types=1);

namespace Pablo\Agents;

use Pablo\Config\Config;

/**
 * Renders the five opencode agent .md files IN MEMORY from the
 * opencode/agents/*.md.template sources plus the issue-tracker providers the
 * configured projects use. Shared by `pablo system:generate-agents` (writes
 * the rendered files) and `pablo system:doctor` (compares a fresh render
 * against what is installed under ~/.config/opencode) so the two can never
 * drift apart. Reads only — never writes.
 */
final class AgentTemplates
{
    public const AGENT_NAMES = [
        'task-analyst',
        'task-feedback',
        'ci-analyst',
        'pr-feedback',
        'rebase-conflict-resolver',
    ];

    public function __construct(private readonly Config $config)
    {
    }

    /**
     * Repo directory holding the *.md.template sources (install.sh symlinks
     * the generated siblings into ~/.config/opencode).
     */
    public function repoAgentsDir(): string
    {
        return \dirname(__DIR__, 3).'/opencode/agents';
    }

    /**
     * Providers any configured project uses — all of them when no projects
     * directory exists yet. Resolved per call (like every other PABLO_* /
     * HOME derived default) so a cached DI container can never freeze it.
     *
     * @return list<string>
     */
    public function enabledProviders(?string $projectsDir = null): array
    {
        $projectsDir ??= $this->config->projectsDir();
        if (!is_dir($projectsDir)) {
            return Config::PROVIDERS;
        }

        $providers = [];
        foreach (glob(rtrim($projectsDir, '/').'/*.yaml') ?: [] as $path) {
            $data = $this->config->loadYaml($path);
            if (isset($data['issue_tracker']['provider'])) {
                $providers[] = $data['issue_tracker']['provider'];
            }
        }

        return array_values(array_unique($providers));
    }

    /**
     * Render one agent's .md content exactly as system:generate-agents
     * writes it to disk.
     *
     * @param list<string> $enabledProviders
     *
     * @throws AgentTemplateError when the template, a provider section or a
     *                            shared frontmatter profile is missing/unreadable
     */
    public function render(string $agentsDir, string $name, array $enabledProviders): string
    {
        $templatePath = $agentsDir.'/'.$name.'.md.template';
        if (!is_file($templatePath)) {
            throw new AgentTemplateError("Template not found: {$templatePath}");
        }

        $template = file_get_contents($templatePath);
        if (false === $template) {
            throw new AgentTemplateError("Cannot read template: {$templatePath}");
        }

        $section = $this->buildSection($enabledProviders, $name, $agentsDir.'/providers');
        $names = $this->formatProviderNames($enabledProviders);

        $content = str_replace(
            ['{{ISSUE_TRACKER_SECTION}}', '{{ISSUE_TRACKER_NAMES}}'],
            [$section, $names],
            $template,
        );

        $sharedTokens = preg_match_all('/\{\{SHARED_FRONTMATTER:([a-z-]+)\}\}/', $content, $tokenMatches)
            ? array_unique($tokenMatches[1])
            : [];

        foreach ($sharedTokens as $profile) {
            $profilePath = $agentsDir.'/shared/'.$profile.'.frontmatter.md';
            $frontmatter = @file_get_contents($profilePath);
            if (false === $frontmatter || '' === trim($frontmatter)) {
                throw new AgentTemplateError("Shared frontmatter profile not found: {$profilePath}");
            }

            $content = str_replace(
                '{{SHARED_FRONTMATTER:'.$profile.'}}'."\n",
                rtrim($frontmatter, "\n")."\n",
                $content,
            );
        }

        return $content;
    }

    /**
     * @param list<string> $providers
     */
    private function formatProviderNames(array $providers): string
    {
        $displayNames = array_map(
            static fn (string $p): string => match ($p) {
                'github' => 'GitHub Issues',
                'jira' => 'Jira',
                'linear' => 'Linear',
                default => $p,
            },
            $providers,
        );

        if ([] === $displayNames) {
            return 'your issue tracker';
        }

        if (1 === \count($displayNames)) {
            return $displayNames[0];
        }

        $last = array_pop($displayNames);

        return implode(', ', $displayNames).', or '.$last;
    }

    /**
     * @param list<string> $providers
     */
    private function buildSection(array $providers, string $agentName, string $providersDir): string
    {
        $items = [];
        $num = 1;

        foreach ($providers as $provider) {
            $item = $this->providerItem($providersDir.'/'.$provider.'-'.$agentName.'.md');
            if (null !== $item) {
                $items[] = $num.'. '.$item;
                ++$num;
            }
        }

        if (\in_array('jira', $providers, true)) {
            $item = $this->providerItem($providersDir.'/confluence-'.$agentName.'.md');
            if (null !== $item) {
                $items[] = $num.'. '.$item;
                ++$num;
            }
        }

        $fallback = $this->providerItem($providersDir.'/fallback-'.$agentName.'.md');
        if (null !== $fallback) {
            $items[] = $num.'. '.$fallback;
        }

        return implode("\n\n", $items);
    }

    private function providerItem(string $file): ?string
    {
        if (!is_file($file)) {
            return null;
        }

        $content = file_get_contents($file);
        if (false === $content || '' === trim($content)) {
            return null;
        }

        return trim($content);
    }
}
